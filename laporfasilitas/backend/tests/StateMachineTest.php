<?php

declare(strict_types=1);

// Jalankan: php backend/tests/StateMachineTest.php   (tanpa Composer/PHPUnit)
require __DIR__ . '/../bootstrap.php';

use App\Domain\ReportStateMachine;
use App\Domain\ReportStatus;
use App\Domain\Role;
use App\Domain\Transition;
use App\Domain\TransitionContext;
use App\Domain\TransitionException;
use App\Domain\Who;

$pass = 0;
$fail = 0;

function check(bool $ok, string $name): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        fwrite(STDERR, "GAGAL: {$name}\n");
    }
}

/** Konteks yang memenuhi SEMUA syarat sebuah aturan (jalur bahagia). */
function happyContext(Transition $t, ?Who $via = null): TransitionContext
{
    $who = $via ?? $t->who[0];

    return new TransitionContext(
        role: match ($who) {
            Who::Admin => Role::Admin,
            Who::System => Role::System,
            default => Role::User,
        },
        isOwner: $who === Who::Owner,
        isClaimHolder: $who === Who::ClaimHolder,
        reason: 'alasan uji',
        autoPublishEnabled: true,
        hasActiveClaim: false,
        evidenceSubmitted: true,
        evidenceApproved: true,
        reopenApproved: true,
    );
}

function expectError(callable $fn, int $status, string $code, string $name): void
{
    try {
        $fn();
        check(false, "{$name} (tidak melempar galat)");
    } catch (TransitionException $e) {
        check($e->httpStatus === $status && $e->errorCode === $code, "{$name} (dapat {$e->httpStatus}/{$e->errorCode})");
    }
}

$sm = new ReportStateMachine();
$rules = ReportStateMachine::rules();

// 1. Jalur bahagia: tiap aturan, tiap kapabilitas yang sah, tiap status asal.
foreach ($rules as $t) {
    foreach ($t->who as $who) {
        foreach ($t->from as $from) {
            $got = $sm->apply($from, $t->to, happyContext($t, $who));
            check($got->id === $t->id, "{$t->id} jalur bahagia {$from->value}->{$t->to->value} via {$who->value}");
        }
    }
}

// 2. ID aturan unik.
$ids = array_map(static fn (Transition $t): string => $t->id, $rules);
check(count($ids) === count(array_unique($ids)), 'ID transisi unik');

// 3. Aturan yang mensyaratkan alasan menolak alasan kosong dan spasi (422).
foreach ($rules as $t) {
    if (!$t->requiresReason) {
        continue;
    }
    foreach ([null, '', '   '] as $bad) {
        $ctx = happyContext($t);
        $noReason = new TransitionContext(
            $ctx->role, $ctx->isOwner, $ctx->isClaimHolder, $bad,
            $ctx->autoPublishEnabled, $ctx->hasActiveClaim, $ctx->evidenceSubmitted,
            $ctx->evidenceApproved, $ctx->reopenApproved,
        );
        expectError(
            static fn () => $sm->apply($t->from[0], $t->to, $noReason),
            422, 'reason_required', "{$t->id} tanpa alasan",
        );
    }
}

// 4. Otorisasi: peran yang tidak berhak ditolak 403.
foreach ($rules as $t) {
    $outsiders = [
        'volunteer biasa' => new TransitionContext(Role::Volunteer, reason: 'x', autoPublishEnabled: true,
            evidenceSubmitted: true, evidenceApproved: true, reopenApproved: true),
        'pengguna biasa' => new TransitionContext(Role::User, reason: 'x', autoPublishEnabled: true,
            evidenceSubmitted: true, evidenceApproved: true, reopenApproved: true),
        'admin' => new TransitionContext(Role::Admin, reason: 'x', autoPublishEnabled: true,
            evidenceSubmitted: true, evidenceApproved: true, reopenApproved: true),
        'system' => new TransitionContext(Role::System, reason: 'x', autoPublishEnabled: true,
            evidenceSubmitted: true, evidenceApproved: true, reopenApproved: true),
    ];
    foreach ($outsiders as $label => $ctx) {
        $shouldPass = $sm->can($t->from[0], $t->to, $ctx);
        $expected = false;
        foreach ($t->who as $w) {
            $expected = $expected || match ($w) {
                Who::Authenticated => in_array($ctx->role, [Role::User, Role::Volunteer], true),
                Who::Admin => $ctx->role === Role::Admin,
                Who::System => $ctx->role === Role::System,
                default => false, // Owner/ClaimHolder butuh isOwner/isClaimHolder, tidak diset di sini
            };
        }
        check($shouldPass === $expected, "{$t->id} otorisasi untuk {$label}");
    }
}

// 5. Volunteer TIDAK boleh melakukan transisi moderasi/penyelesaian apa pun (F7).
$volunteer = new TransitionContext(Role::Volunteer, isOwner: false, reason: 'x', autoPublishEnabled: true,
    evidenceSubmitted: true, evidenceApproved: true, reopenApproved: true);
foreach (['T-06a', 'T-06b', 'T-06c', 'T-11', 'T-12', 'T-13', 'T-15'] as $id) {
    $t = array_values(array_filter($rules, static fn (Transition $r): bool => $r->id === $id))[0];
    check(!$sm->can($t->from[0], $t->to, $volunteer), "volunteer tidak boleh {$id}");
}

// 6. Pemilik harus benar-benar pemilik; pemegang klaim harus benar-benar pemegang.
expectError(
    static fn () => $sm->apply(ReportStatus::Draft, ReportStatus::MenungguAnalisis, new TransitionContext(Role::User, isOwner: false)),
    403, 'forbidden', 'bukan pemilik mengirim laporan',
);
expectError(
    static fn () => $sm->apply(ReportStatus::DalamPenanganan, ReportStatus::MenungguKonfirmasi,
        new TransitionContext(Role::User, isClaimHolder: false, evidenceSubmitted: true)),
    403, 'forbidden', 'bukan pemegang klaim mengirim bukti',
);

// 7. Guard.
$admin = static fn (array $o = []): TransitionContext => new TransitionContext(
    Role::Admin, reason: 'x',
    evidenceApproved: $o['approved'] ?? false, reopenApproved: $o['reopen'] ?? false,
);
expectError(
    static fn () => $sm->apply(ReportStatus::MenungguKonfirmasi, ReportStatus::Selesai, $admin()),
    409, 'guard_failed', 'Selesai tanpa bukti disetujui',
);
check($sm->can(ReportStatus::MenungguKonfirmasi, ReportStatus::Selesai, $admin(['approved' => true])), 'Selesai dengan bukti disetujui');
expectError(
    static fn () => $sm->apply(ReportStatus::Selesai, ReportStatus::DibukaKembali, $admin()),
    409, 'guard_failed', 'buka kembali tanpa pengajuan disetujui',
);
expectError(
    static fn () => $sm->apply(ReportStatus::MenungguAnalisis, ReportStatus::Dipublikasikan,
        new TransitionContext(Role::System, autoPublishEnabled: false)),
    409, 'guard_failed', 'auto-publish saat flag mati',
);
expectError(
    static fn () => $sm->apply(ReportStatus::MenungguTindakLanjut, ReportStatus::DalamPenanganan,
        new TransitionContext(Role::User, hasActiveClaim: true)),
    409, 'guard_failed', 'klaim kedua saat sudah ada klaim aktif',
);
expectError(
    static fn () => $sm->apply(ReportStatus::DalamPenanganan, ReportStatus::MenungguKonfirmasi,
        new TransitionContext(Role::User, isClaimHolder: true, evidenceSubmitted: false)),
    409, 'guard_failed', 'kirim bukti tanpa bukti valid',
);

// 8. Matriks lengkap: pasangan (dari, ke) yang tidak ada di tabel aturan SELALU 409.
$defined = [];
foreach ($rules as $t) {
    foreach ($t->from as $f) {
        $defined[$f->value . '>' . $t->to->value] = true;
    }
}
$undefined = 0;
$superuser = new TransitionContext(Role::Admin, isOwner: true, isClaimHolder: true, reason: 'x',
    autoPublishEnabled: true, hasActiveClaim: false, evidenceSubmitted: true, evidenceApproved: true, reopenApproved: true);
foreach (ReportStatus::cases() as $from) {
    foreach (ReportStatus::cases() as $to) {
        if (isset($defined[$from->value . '>' . $to->value])) {
            continue;
        }
        $undefined++;
        expectError(static fn () => $sm->apply($from, $to, $superuser), 409, 'invalid_transition',
            "tak terdefinisi {$from->value}->{$to->value}");
    }
}

// 9. Invarian struktural.
$toSelesai = array_filter($rules, static fn (Transition $t): bool => $t->to === ReportStatus::Selesai);
check(count($toSelesai) === 1 && array_values($toSelesai)[0]->id === 'T-12', 'hanya T-12 yang menuju Selesai');

$fromDitolak = array_filter($rules, static fn (Transition $t): bool => in_array(ReportStatus::Ditolak, $t->from, true));
check($fromDitolak === [], 'Ditolak final: tidak ada transisi keluar');

$toDraft = array_filter($rules, static fn (Transition $t): bool => $t->to === ReportStatus::Draft);
check($toDraft === [], 'Draft hanya titik awal');

$toSelesaiWho = array_values($toSelesai)[0]->who;
check($toSelesaiWho === [Who::Admin], 'Selesai hanya oleh Admin');

foreach (ReportStatus::cases() as $s) {
    if ($s !== ReportStatus::Draft) {
        $hasIn = array_filter($rules, static fn (Transition $t): bool => $t->to === $s) !== [];
        check($hasIn, "{$s->value} dapat dicapai");
    }
    if ($s !== ReportStatus::Ditolak) {
        $hasOut = array_filter($rules, static fn (Transition $t): bool => in_array($s, $t->from, true)) !== [];
        check($hasOut, "{$s->value} punya jalan keluar");
    }
}

// 10. Takedown T-15 hanya untuk status publik.
foreach (ReportStatus::cases() as $s) {
    $ok = $sm->can($s, ReportStatus::Ditolak, new TransitionContext(Role::Admin, reason: 'melanggar'));
    $expected = $s->isPublic() || $s === ReportStatus::PerluReview;
    check($ok === $expected, "takedown/tolak dari {$s->value}: " . ($expected ? 'boleh' : 'tidak boleh'));
}

echo "\nAturan: " . count($rules) . " | pasangan tak terdefinisi diuji: {$undefined}\n";
echo "Lulus: {$pass}  Gagal: {$fail}\n";
exit($fail === 0 ? 0 : 1);
