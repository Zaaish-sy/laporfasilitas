<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Sumber tunggal aturan perubahan status laporan.
 *
 * Prinsip (lihat docs/DECISIONS.md):
 *  - Klien hanya mengirim AKSI. Backend menghitung konteks lalu memanggil apply().
 *  - Konfirmasi komunitas, hasil AI, dan lamanya waktu BUKAN alasan sah menuju Selesai (F6).
 *  - Volunteer hanya merekomendasikan; tidak ada transisi yang boleh dilakukan Volunteer
 *    selain sebagai pengguna biasa (T-08, T-09).
 *  - Setiap apply() yang berhasil HARUS diikuti penulisan status_history dalam transaksi yang sama.
 */
final class ReportStateMachine
{
    /** @var list<Transition>|null */
    private static ?array $rules = null;

    /** @return list<Transition> */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        return self::$rules = [
            new Transition('T-01', [ReportStatus::Draft], ReportStatus::MenungguAnalisis, [Who::Owner],
                note: 'Pemilik mengirim laporan (POST /reports/{id}/submit).'),

            new Transition('T-02', [ReportStatus::MenungguAnalisis], ReportStatus::Dipublikasikan, [Who::System],
                guard: static fn (TransitionContext $c): ?string => $c->autoPublishEnabled
                    ? null : 'publikasi otomatis dimatikan (Keputusan #1); arahkan ke Perlu Review',
                note: 'AI Passed + aturan non-AI lolos. Hanya jika flag auto-publish aktif (A-01).'),

            new Transition('T-03', [ReportStatus::MenungguAnalisis], ReportStatus::PerluReview, [Who::System],
                note: 'Needs Review, AI gagal (A-02), di luar area pilot (A-09), atau terindikasi duplikat.'),

            new Transition('T-04', [ReportStatus::MenungguAnalisis], ReportStatus::PerluPerbaikan, [Who::System],
                requiresReason: true, note: 'Hasil AI Rejected (A-03).'),

            new Transition('T-05', [ReportStatus::PerluPerbaikan], ReportStatus::MenungguAnalisis, [Who::Owner],
                note: 'Pemilik mengganti foto/data lalu mengirim ulang.'),

            new Transition('T-06a', [ReportStatus::PerluReview], ReportStatus::Dipublikasikan, [Who::Admin],
                note: 'Keputusan admin: publikasikan.'),

            new Transition('T-06b', [ReportStatus::PerluReview], ReportStatus::PerluPerbaikan, [Who::Admin],
                requiresReason: true, note: 'Keputusan admin: minta perbaikan.'),

            new Transition('T-06c', [ReportStatus::PerluReview], ReportStatus::Ditolak, [Who::Admin],
                requiresReason: true, note: 'Keputusan admin: tolak / duplikat.'),

            new Transition('T-07', [ReportStatus::Dipublikasikan], ReportStatus::MenungguTindakLanjut, [Who::System],
                note: 'Otomatis setelah dipublikasikan (A-04).'),

            new Transition('T-08', [ReportStatus::MenungguTindakLanjut], ReportStatus::DalamPenanganan, [Who::Authenticated],
                guard: static fn (TransitionContext $c): ?string => $c->hasActiveClaim
                    ? 'sudah ada pihak aktif yang menangani (A-06)' : null,
                note: 'Pengguna menyatakan bersedia menangani. Wajib online (A-13).'),

            new Transition('T-09', [ReportStatus::DalamPenanganan], ReportStatus::MenungguTindakLanjut,
                [Who::ClaimHolder, Who::System], requiresReason: true,
                note: 'Klaim dilepas pemegangnya, atau dilepas sistem (batas waktu, Keputusan #7/#15).'),

            new Transition('T-10', [ReportStatus::DalamPenanganan], ReportStatus::MenungguKonfirmasi, [Who::ClaimHolder],
                guard: static fn (TransitionContext $c): ?string => $c->evidenceSubmitted
                    ? null : 'bukti penanganan belum terkirim/valid',
                note: 'Pemegang klaim mengirim bukti (A-05).'),

            new Transition('T-11', [ReportStatus::MenungguKonfirmasi], ReportStatus::DalamPenanganan, [Who::Admin],
                requiresReason: true, note: 'Bukti tidak memadai.'),

            new Transition('T-12', [ReportStatus::MenungguKonfirmasi], ReportStatus::Selesai, [Who::Admin],
                guard: static fn (TransitionContext $c): ?string => $c->evidenceApproved
                    ? null : 'tidak ada bukti yang disetujui; Selesai tidak boleh karena waktu atau konfirmasi saja (F6)',
                note: 'SATU-SATUNYA jalan menuju Selesai.'),

            new Transition('T-13', [ReportStatus::Selesai], ReportStatus::DibukaKembali, [Who::Admin],
                requiresReason: true,
                guard: static fn (TransitionContext $c): ?string => $c->reopenApproved
                    ? null : 'belum ada pengajuan buka kembali yang disetujui (Keputusan #6)',
                note: 'Pengajuan konfirmasi ulang terbukti.'),

            new Transition('T-14', [ReportStatus::DibukaKembali], ReportStatus::MenungguTindakLanjut, [Who::System],
                note: 'Otomatis (A-04).'),

            new Transition('T-15', ReportStatus::publicStatuses(), ReportStatus::Ditolak, [Who::Admin],
                requiresReason: true, note: 'Takedown konten melanggar (UC-18). Tercatat, tidak dihapus (Keputusan #17).'),
        ];
    }

    /**
     * Terapkan permintaan transisi. Mengembalikan aturan yang berlaku, atau melempar TransitionException:
     *  409 invalid_transition / guard_failed, 403 forbidden, 422 reason_required.
     */
    public function apply(ReportStatus $from, ReportStatus $to, TransitionContext $ctx): Transition
    {
        $candidates = array_values(array_filter(
            self::rules(),
            static fn (Transition $t): bool => $t->to === $to && in_array($from, $t->from, true),
        ));

        if ($candidates === []) {
            throw TransitionException::invalid($from, $to);
        }

        $allowed = null;
        foreach ($candidates as $t) {
            if ($this->whoSatisfied($t, $ctx)) {
                $allowed = $t;
                break;
            }
        }
        if ($allowed === null) {
            throw TransitionException::forbidden($candidates[0]->id);
        }

        if ($allowed->requiresReason && trim((string) $ctx->reason) === '') {
            throw TransitionException::reasonRequired($allowed->id);
        }

        if ($allowed->guard !== null) {
            $why = ($allowed->guard)($ctx);
            if ($why !== null) {
                throw TransitionException::guard($allowed->id, $why);
            }
        }

        return $allowed;
    }

    public function can(ReportStatus $from, ReportStatus $to, TransitionContext $ctx): bool
    {
        try {
            $this->apply($from, $to, $ctx);

            return true;
        } catch (TransitionException) {
            return false;
        }
    }

    private function whoSatisfied(Transition $t, TransitionContext $c): bool
    {
        $isMember = $c->role === Role::User || $c->role === Role::Volunteer;

        foreach ($t->who as $who) {
            $ok = match ($who) {
                Who::Owner => $isMember && $c->isOwner,
                Who::ClaimHolder => $isMember && $c->isClaimHolder,
                Who::Authenticated => $isMember,
                Who::Admin => $c->role === Role::Admin,
                Who::System => $c->role === Role::System,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
