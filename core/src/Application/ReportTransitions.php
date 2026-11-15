<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\ReportStateMachine;
use App\Domain\ReportStatus;
use App\Domain\TransitionContext;

/**
 * Satu-satunya pintu untuk mengubah reports.status. Menegakkan invarian:
 *  1. Baris laporan dikunci (SELECT ... FOR UPDATE) sehingga dua permintaan serempak berurutan.
 *  2. Fakta konteks dihitung dari DATABASE, bukan dari klien (isOwner, klaim, bukti, pengajuan buka kembali).
 *  3. Ubah status + tulis status_history dalam SATU transaksi; galat apa pun membatalkan keduanya.
 *
 * Pola pemakaian untuk aksi yang juga mengubah tabel turunan (klaim, bukti, pengajuan):
 *   $pdo->beginTransaction();
 *   try {
 *       $transitions->transition(...);   // bergabung ke transaksi luar, TIDAK commit sendiri
 *       // ... ubah tabel turunan (mis. INSERT action_claims) ...
 *       $pdo->commit();
 *   } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
 * Urutan: panggil transition() DULU (guard memakai keadaan sebelum aksi), lalu ubah tabel turunan.
 * Untuk keputusan admin atas bukti/pengajuan: tandai approved DULU, baru transition() (guard membacanya).
 */
final class ReportTransitions
{
    private readonly ReportStateMachine $machine;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly bool $autoPublishEnabled = false,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->machine = new ReportStateMachine();
    }

    /**
     * @throws \App\Domain\TransitionException 403/409/422 dari state machine
     * @throws ApiException 404 bila laporan tidak ada
     */
    public function transition(string $reportId, ReportStatus $to, Actor $actor, ?string $reason = null): TransitionResult
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare('SELECT status::text AS status, user_id FROM reports WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $reportId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                throw ApiException::notFound('Laporan');
            }

            $from = ReportStatus::from($row['status']);
            $rule = $this->machine->apply($from, $to, $this->buildContext($reportId, $row['user_id'], $actor, $reason));

            $this->pdo->prepare('UPDATE reports SET status = :s::report_status, updated_at = now() WHERE id = :id')
                ->execute([':s' => $to->value, ':id' => $reportId]);
            $this->pdo->prepare(
                'INSERT INTO status_history (report_id, from_status, to_status, transition_id, actor_id, reason)
                 VALUES (:r, :f::report_status, :t::report_status, :tid, :a, :why)',
            )->execute([
                ':r' => $reportId, ':f' => $from->value, ':t' => $to->value,
                ':tid' => $rule->id, ':a' => $actor->userId, ':why' => $reason,
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return new TransitionResult($from, $to, $rule->id);
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function buildContext(string $reportId, string $ownerId, Actor $actor, ?string $reason): TransitionContext
    {
        $uid = $actor->userId;

        $hasActiveClaim = $this->exists(
            "SELECT 1 FROM action_claims WHERE report_id = :r AND state = 'active'",
            [':r' => $reportId],
        );
        $isClaimHolder = $uid !== null && $this->exists(
            "SELECT 1 FROM action_claims WHERE report_id = :r AND state = 'active' AND user_id = :u",
            [':r' => $reportId, ':u' => $uid],
        );
        // Bukti menunggu pemeriksaan pada klaim aktif.
        $evidenceSubmitted = $this->exists(
            "SELECT 1 FROM resolution_evidence e JOIN action_claims c ON c.id = e.claim_id
              WHERE c.report_id = :r AND c.state = 'active' AND e.review_state = 'pending'",
            [':r' => $reportId],
        );
        // Bukti yang sudah disetujui pada klaim aktif (admin menandainya SEBELUM memanggil transition()).
        $evidenceApproved = $this->exists(
            "SELECT 1 FROM resolution_evidence e JOIN action_claims c ON c.id = e.claim_id
              WHERE c.report_id = :r AND c.state = 'active' AND e.review_state = 'approved'",
            [':r' => $reportId],
        );
        // Pengajuan buka kembali yang disetujui DAN dibuat setelah laporan terakhir menjadi Selesai
        // (mencegah persetujuan lama dipakai ulang pada siklus berikutnya).
        $reopenApproved = $this->exists(
            "SELECT 1 FROM reopen_requests q
              WHERE q.report_id = :r AND q.review_state = 'approved'
                AND q.created_at > COALESCE(
                    (SELECT max(changed_at) FROM status_history WHERE report_id = :r2 AND to_status = 'selesai'),
                    '-infinity'::timestamptz)",
            [':r' => $reportId, ':r2' => $reportId],
        );

        return new TransitionContext(
            role: $actor->role,
            isOwner: $uid !== null && $uid === $ownerId,
            isClaimHolder: $isClaimHolder,
            reason: $reason,
            autoPublishEnabled: $this->autoPublishEnabled,
            hasActiveClaim: $hasActiveClaim,
            evidenceSubmitted: $evidenceSubmitted,
            evidenceApproved: $evidenceApproved,
            reopenApproved: $reopenApproved,
        );
    }

    /** @param array<string, mixed> $params */
    private function exists(string $sql, array $params): bool
    {
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }
}
