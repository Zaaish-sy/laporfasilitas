<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\ReportStatus;

/**
 * Memproses laporan berstatus menunggu_analisis (UC-21). Dipanggil setelah submit (sinkron atau lewat antrean).
 *
 * Prinsip (F4, F11):
 *  - AI hanya mengembalikan hasil; VERDICT dihitung di sini lewat VerificationPolicy.
 *  - AI tidak tersedia -> Perlu Review (A-02), TIDAK PERNAH lolos otomatis.
 *  - Hanya foto yang BELUM punya analisis yang dianalisis (A-16): pada pengiriman ulang, foto lama tidak
 *    dihitung lagi. Pengiriman ulang tanpa foto baru -> Perlu Review.
 *  - Passed belum berarti dipublikasikan: butuh flag publikasi otomatis (Keputusan #1; dibaca dari ReportTransitions,
 *    satu-satunya sumber kebenaran) DAN aturan non-AI lolos
 *    (area pilot, A-09). Selain itu -> Perlu Review.
 *  - Idempoten: bila status bukan menunggu_analisis (sudah diproses/ditarik), tidak melakukan apa pun.
 */
final class AnalysisProcessor
{
    private const SEVERITY = ['passed' => 0, 'needs_review' => 1, 'rejected' => 2];

    /**
     * @param array{minLat: float, minLon: float, maxLat: float, maxLon: float}|null $pilotBounds
     *        null = pemeriksaan area dimatikan. Koordinat batas pilot Samarinda belum ditetapkan (Keputusan #10).
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ReportTransitions $transitions,
        private readonly AiClient $ai,
        private readonly VerificationPolicy $policy = new VerificationPolicy(),
        private readonly ?array $pilotBounds = null,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** @return TransitionResult|null hasil transisi terakhir, atau null bila tidak ada yang diproses */
    public function process(string $reportId): ?TransitionResult
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.status::text AS status, c.name AS category, l.lat, l.lon
               FROM reports r JOIN categories c ON c.id = r.category_id JOIN report_locations l ON l.report_id = r.id
              WHERE r.id = :id',
        );
        $stmt->execute([':id' => $reportId]);
        $report = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($report === false || $report['status'] !== ReportStatus::MenungguAnalisis->value) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.storage_key FROM report_images i
              WHERE i.report_id = :r AND NOT EXISTS (SELECT 1 FROM ai_analyses a WHERE a.image_id = i.id)
              ORDER BY i.created_at, i.id',
        );
        $stmt->execute([':r' => $reportId]);
        $newImages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // --- Panggilan AI di LUAR transaksi (jaringan lambat tidak boleh menahan kunci baris) ---
        $analyses = [];
        $aiFailed = false;
        foreach ($newImages as $img) {
            try {
                $res = $this->ai->analyze($img['storage_key'], $report['category']);
            } catch (AiUnavailable) {
                $aiFailed = true;
                break;
            }
            $analyses[] = ['image_id' => $img['id'], 'result' => $res, 'verdict' => $this->policy->decide($res)];
        }

        [$target, $reason, $publish] = $this->decideOutcome($newImages === [], $aiFailed, $analyses, $report);

        // --- Terapkan secara atomik ---
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status::text FROM reports WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $reportId]);
            if ($lock->fetchColumn() !== ReportStatus::MenungguAnalisis->value) {      // keburu diproses pihak lain
                $this->pdo->rollBack();

                return null;
            }

            if (!$aiFailed) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO ai_analyses (image_id, predicted_class, confidence, category_match, image_quality,
                                              verification_result, model_version, threshold_version)
                     VALUES (:i, :p, :c, :m, :q, :v::ai_result, :mv, :tv)',
                );
                foreach ($analyses as $a) {
                    $r = $a['result'];
                    $ins->execute([
                        ':i' => $a['image_id'], ':p' => $r->predictedClass, ':c' => $r->confidence,
                        ':m' => $r->categoryMatch ? 'true' : 'false', ':q' => $r->imageQuality,
                        ':v' => $a['verdict']->value, ':mv' => $r->modelVersion, ':tv' => $this->policy->thresholdVersion,
                    ]);
                }
            }

            $system = Actor::system();
            $result = $this->transitions->transition($reportId, $target, $system, $reason);
            if ($publish) {
                $result = $this->transitions->transition($reportId, ReportStatus::MenungguTindakLanjut, $system);   // T-07
            }
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<array{image_id: string, result: AiResult, verdict: Verification}> $analyses
     * @param array<string, mixed> $report
     * @return array{0: ReportStatus, 1: ?string, 2: bool} [status tujuan, alasan, apakah lanjut T-07]
     */
    private function decideOutcome(bool $noNewImages, bool $aiFailed, array $analyses, array $report): array
    {
        if ($aiFailed) {
            return [ReportStatus::PerluReview, 'Analisis AI tidak tersedia; laporan diperiksa manual.', false];          // A-02
        }
        if ($noNewImages) {
            return [ReportStatus::PerluReview, 'Tidak ada foto baru untuk dianalisis; diperiksa manual.', false];        // A-16
        }

        $worst = Verification::Passed;
        foreach ($analyses as $a) {
            if (self::SEVERITY[$a['verdict']->value] > self::SEVERITY[$worst->value]) {
                $worst = $a['verdict'];
            }
        }

        if ($worst === Verification::Rejected) {
            return [ReportStatus::PerluPerbaikan, 'Foto tidak layak dianalisis (kualitas gambar rendah). Mohon ganti foto.', false];   // T-04
        }
        if ($worst === Verification::NeedsReview) {
            return [ReportStatus::PerluReview, null, false];                                                              // T-03
        }

        // Passed oleh AI: belum cukup. Perlu flag publikasi otomatis DAN aturan non-AI.
        if (!$this->transitions->autoPublishEnabled()) {
            return [ReportStatus::PerluReview, 'Lolos pemeriksaan awal AI; menunggu pemeriksaan admin (publikasi otomatis belum aktif).', false];
        }
        if (!$this->insidePilot((float) $report['lat'], (float) $report['lon'])) {
            return [ReportStatus::PerluReview, 'Lokasi di luar area pilot; diperiksa manual.', false];                    // A-09
        }

        return [ReportStatus::Dipublikasikan, null, true];                                                                // T-02 lalu T-07
    }

    private function insidePilot(float $lat, float $lon): bool
    {
        $b = $this->pilotBounds;

        return $b === null
            || ($lat >= $b['minLat'] && $lat <= $b['maxLat'] && $lon >= $b['minLon'] && $lon <= $b['maxLon']);
    }
}
