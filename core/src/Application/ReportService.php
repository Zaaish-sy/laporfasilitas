<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\ReportStatus;
use App\Domain\Role;

/**
 * Modul Laporan (UC-03, UC-04, UC-05). PDO murni agar bisa diuji ke PostgreSQL tanpa Laravel.
 *
 * Aturan visibilitas (diuji):
 *  - Pemilik melihat laporannya sendiri pada status apa pun.
 *  - Volunteer/Admin melihat semua laporan KECUALI draft milik orang lain (draft belum dikirim = privat).
 *  - Pengguna lain hanya melihat status publik. Laporan yang tidak boleh dilihat dijawab 404 (bukan 403)
 *    agar keberadaannya tidak bocor.
 *  - Alasan moderasi, client_uuid, dan hasil AI hanya untuk pemilik dan moderator.
 *
 * Perubahan status TIDAK dilakukan di sini; selalu lewat ReportTransitions.
 */
final class ReportService
{
    private const EDITABLE = ['draft', 'perlu_perbaikan'];
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ReportTransitions $transitions,
        private readonly bool $requireEmailVerification = false,
        private readonly int $maxImagesPerReport = 5,                // PLACEHOLDER: Keputusan #23 belum ditetapkan
        private readonly string $imageUrlTemplate = '/api/v1/images/%s/file',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    // =====================================================================
    // Menulis
    // =====================================================================

    /**
     * Membuat Draft di server. Idempoten: client_uuid yang sama dari pengguna yang sama mengembalikan laporan lama
     * (data masukan baru diabaikan).
     *
     * @param array<string, mixed> $input
     * @return array{report: array<string, mixed>, created: bool}
     */
    public function createDraft(Actor $actor, array $input): array
    {
        $this->assertCanReport($actor);
        $v = $this->validate($input, requireAll: true);
        if (!is_string($input['client_uuid'] ?? null) || preg_match(self::UUID_RE, $input['client_uuid']) !== 1) {
            throw ApiException::validation(['client_uuid' => 'client_uuid wajib berupa UUID.']);
        }
        $clientUuid = strtolower($input['client_uuid']);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO reports (client_uuid, user_id, category_id, title, description)
                 VALUES (:c, :u, :cat, :t, :d)
                 ON CONFLICT ON CONSTRAINT uq_reports_client DO NOTHING
                 RETURNING id',
            );
            $stmt->execute([':c' => $clientUuid, ':u' => $actor->userId, ':cat' => $v['category_id'], ':t' => $v['title'], ':d' => $v['description']]);
            $id = $stmt->fetchColumn();

            if ($id === false) {                                    // sudah ada: kembalikan yang lama
                $stmt = $this->pdo->prepare('SELECT id FROM reports WHERE user_id = :u AND client_uuid = :c');
                $stmt->execute([':u' => $actor->userId, ':c' => $clientUuid]);
                $id = (string) $stmt->fetchColumn();
                $this->pdo->commit();

                return ['report' => $this->get($actor, $id), 'created' => false];
            }

            $id = (string) $id;
            $loc = $v['location'];
            $this->pdo->prepare('INSERT INTO report_locations (report_id, lat, lon, address, source) VALUES (:r, :la, :lo, :a, :s::location_source)')
                ->execute([':r' => $id, ':la' => $loc['lat'], ':lo' => $loc['lon'], ':a' => $loc['address'], ':s' => $loc['source']]);
            $this->pdo->prepare("INSERT INTO status_history (report_id, from_status, to_status, actor_id) VALUES (:r, NULL, 'draft', :a)")
                ->execute([':r' => $id, ':a' => $actor->userId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['report' => $this->get($actor, $id), 'created' => true];
    }

    /**
     * Mengubah draft / laporan yang diminta diperbaiki. Hanya pemilik, hanya status draft atau perlu_perbaikan.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function updateDraft(Actor $actor, string $reportId, array $patch): array
    {
        $this->assertCanReport($actor);
        $v = $this->validate($patch, requireAll: false);

        $this->pdo->beginTransaction();
        try {
            $row = $this->lockOwnedEditable($actor, $reportId);
            $sets = [];
            $p = [':id' => $reportId];
            foreach (['category_id' => 'category_id', 'title' => 'title', 'description' => 'description'] as $key => $col) {
                if (array_key_exists($key, $v)) {
                    $sets[] = "$col = :$key";
                    $p[":$key"] = $v[$key];
                }
            }
            $sets[] = 'updated_at = now()';
            $this->pdo->prepare('UPDATE reports SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($p);

            if (isset($v['location'])) {
                $l = $v['location'];
                $this->pdo->prepare('UPDATE report_locations SET lat = :la, lon = :lo, address = :a, source = :s::location_source WHERE report_id = :r')
                    ->execute([':la' => $l['lat'], ':lo' => $l['lon'], ':a' => $l['address'], ':s' => $l['source'], ':r' => $reportId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($actor, $reportId);
    }

    /**
     * Mencatat foto yang SUDAH tersimpan di penyimpanan (controller yang memvalidasi dan menyimpan berkas).
     * Idempoten via (report, sha256). Foto tidak boleh ditambah setelah laporan dikirim.
     *
     * @return array{image: array{id: string, url: string}, created: bool}
     */
    public function addImage(Actor $actor, string $reportId, string $storageKey, string $sha256, ?string $takenAt = null): array
    {
        $this->assertCanReport($actor);
        $sha256 = strtolower($sha256);
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw ApiException::validation(['sha256' => 'sha256 wajib 64 karakter heksadesimal.']);
        }

        $this->pdo->beginTransaction();
        try {
            $this->lockOwnedEditable($actor, $reportId);

            $stmt = $this->pdo->prepare(
                'INSERT INTO report_images (report_id, storage_key, sha256, taken_at) VALUES (:r, :k, :h, :t)
                 ON CONFLICT ON CONSTRAINT uq_image_per_report DO NOTHING RETURNING id',
            );
            $count = (int) $this->scalar('SELECT count(*) FROM report_images WHERE report_id = :r', [':r' => $reportId]);
            if ($count >= $this->maxImagesPerReport) {
                $existing = $this->scalar('SELECT id FROM report_images WHERE report_id = :r AND sha256 = :h', [':r' => $reportId, ':h' => $sha256]);
                if ($existing === false) {
                    throw ApiException::validation(['file' => "Jumlah foto melebihi batas ({$this->maxImagesPerReport})."]);
                }
            }
            $stmt->execute([':r' => $reportId, ':k' => $storageKey, ':h' => $sha256, ':t' => $takenAt]);
            $id = $stmt->fetchColumn();
            $created = $id !== false;
            if (!$created) {
                $id = $this->scalar('SELECT id FROM report_images WHERE report_id = :r AND sha256 = :h', [':r' => $reportId, ':h' => $sha256]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['image' => ['id' => (string) $id, 'url' => sprintf($this->imageUrlTemplate, $id)], 'created' => $created];
    }

    /**
     * Mengirim laporan: draft -> menunggu_analisis (T-01) atau perlu_perbaikan -> menunggu_analisis (T-05).
     * Analisis AI dijalankan terpisah oleh AnalysisProcessor.
     *
     * @return array<string, mixed>
     */
    public function submit(Actor $actor, string $reportId): array
    {
        $this->assertCanReport($actor);

        $this->pdo->beginTransaction();
        try {
            $row = $this->lockRow($reportId);
            if ($row === null || !$this->canView($row, $actor)) {
                throw ApiException::notFound('Laporan');
            }
            if ($row['user_id'] !== $actor->userId) {
                throw ApiException::forbidden('Hanya pemilik yang boleh mengirim laporan.');
            }
            $images = (int) $this->scalar('SELECT count(*) FROM report_images WHERE report_id = :r', [':r' => $reportId]);
            if (in_array($row['status'], self::EDITABLE, true) && $images === 0) {
                throw ApiException::validation(['images' => 'Laporan wajib memiliki minimal satu foto.']);
            }
            $this->transitions->transition($reportId, ReportStatus::MenungguAnalisis, $actor);   // 403/409 dari state machine
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($actor, $reportId);
    }

    // =====================================================================
    // Membaca
    // =====================================================================

    /** @return array<string, mixed> */
    public function get(Actor $viewer, string $reportId): array
    {
        $row = $this->fetchRow($reportId);
        if ($row === null || !$this->canView($row, $viewer)) {
            throw ApiException::notFound('Laporan');
        }

        return $this->present([$row], $viewer)[0];
    }

    /**
     * Laporan berstatus publik, terbaru dulu, paginasi kursor.
     *
     * @param array{category_id?: int|string|null, status?: string|null, bbox?: string|null, cursor?: string|null, limit?: int|string|null} $filters
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function listPublic(Actor $viewer, array $filters = []): array
    {
        $publicValues = array_map(static fn (ReportStatus $s): string => $s->value, ReportStatus::publicStatuses());
        $where = ['r.status::text = ANY(:statuses::text[])'];
        $params = [':statuses' => '{' . implode(',', $publicValues) . '}'];

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            if (!in_array($filters['status'], $publicValues, true)) {
                throw ApiException::validation(['status' => 'Status tidak tersedia pada daftar publik.']);
            }
            $where[] = 'r.status::text = :status';
            $params[':status'] = $filters['status'];
        }

        return $this->page($viewer, $where, $params, $filters);
    }

    /**
     * Laporan milik sendiri pada status apa pun.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function listMine(Actor $actor, array $filters = []): array
    {
        $this->assertCanReport($actor);
        $where = ['r.user_id = :me'];
        $params = [':me' => $actor->userId];
        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            if (ReportStatus::tryFrom((string) $filters['status']) === null) {
                throw ApiException::validation(['status' => 'Status tidak dikenal.']);
            }
            $where[] = 'r.status::text = :status';
            $params[':status'] = $filters['status'];
        }

        return $this->page($actor, $where, $params, $filters);
    }

    /** @return list<array{id: int, name: string, active: bool}> */
    public function listCategories(): array
    {
        $rows = $this->pdo->query('SELECT id, name, active FROM categories WHERE active ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'active' => (bool) $r['active']], $rows);
    }

    /**
     * Riwayat status. Pemilik dan moderator melihat alasan dan pelaku; pengguna lain tidak.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Actor $viewer, string $reportId): array
    {
        $row = $this->fetchRow($reportId);
        if ($row === null || !$this->canView($row, $viewer)) {
            throw ApiException::notFound('Laporan');
        }
        $privileged = $this->isPrivileged($row, $viewer);

        $stmt = $this->pdo->prepare(
            "SELECT h.from_status::text AS from_status, h.to_status::text AS to_status, h.transition_id, h.reason, h.changed_at,
                    u.display_name AS actor_name
               FROM status_history h LEFT JOIN users u ON u.id = h.actor_id
              WHERE h.report_id = :r ORDER BY h.id",
        );
        $stmt->execute([':r' => $reportId]);

        return array_map(fn (array $h): array => [
            'from_status' => $h['from_status'],
            'to_status' => $h['to_status'],
            'transition_id' => $privileged ? $h['transition_id'] : null,
            'actor_display_name' => $privileged ? $h['actor_name'] : null,
            'reason' => $privileged ? $h['reason'] : null,
            'changed_at' => $this->iso($h['changed_at']),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Hasil AI. Hanya pemilik dan moderator (F4: confidence bukan persentase kebenaran laporan).
     *
     * @return list<array<string, mixed>>
     */
    public function analysis(Actor $viewer, string $reportId): array
    {
        $row = $this->fetchRow($reportId);
        if ($row === null || !$this->canView($row, $viewer)) {
            throw ApiException::notFound('Laporan');
        }
        if (!$this->isPrivileged($row, $viewer)) {
            throw ApiException::forbidden('Hasil analisis hanya untuk pemilik laporan dan moderator.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT a.image_id, a.predicted_class, a.confidence, a.category_match, a.image_quality,
                    a.verification_result::text AS verification_result, a.model_version, a.threshold_version
               FROM ai_analyses a JOIN report_images i ON i.id = a.image_id
              WHERE i.report_id = :r ORDER BY a.created_at DESC, a.id',
        );
        $stmt->execute([':r' => $reportId]);

        return array_map(static fn (array $a): array => [
            'image_id' => $a['image_id'],
            'predicted_class' => $a['predicted_class'],
            'confidence' => $a['confidence'] === null ? null : (float) $a['confidence'],
            'category_match' => $a['category_match'] === null ? null : (bool) $a['category_match'],
            'image_quality' => $a['image_quality'],
            'verification_result' => $a['verification_result'],
            'model_version' => $a['model_version'],
            'threshold_version' => $a['threshold_version'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Kunci penyimpanan sebuah foto bila pemirsa boleh melihat laporannya; selain itu 404. */
    public function imageStorageKey(Actor $viewer, string $imageId): string
    {
        $stmt = $this->pdo->prepare('SELECT i.storage_key, i.report_id FROM report_images i WHERE i.id = :i');
        try {
            $stmt->execute([':i' => $imageId]);
        } catch (\PDOException) {
            throw ApiException::notFound('Foto');                      // id bukan UUID valid
        }
        $img = $stmt->fetch(\PDO::FETCH_ASSOC);
        $row = $img === false ? null : $this->fetchRow($img['report_id']);
        if ($row === null || !$this->canView($row, $viewer)) {
            throw ApiException::notFound('Foto');
        }

        return $img['storage_key'];
    }

    // =====================================================================
    // Internal
    // =====================================================================

    private function assertCanReport(Actor $actor): void
    {
        if ($actor->userId === null || !in_array($actor->role, [Role::User, Role::Volunteer], true)) {
            throw ApiException::forbidden('Hanya pengguna dan volunteer yang dapat membuat laporan.');
        }
        if ($this->requireEmailVerification
            && $this->scalar('SELECT email_verified_at FROM users WHERE id = :u', [':u' => $actor->userId]) === null) {
            throw ApiException::forbidden('Verifikasi email diperlukan sebelum membuat laporan.', 'email_not_verified');
        }
    }

    /** @return array<string, mixed> baris terkunci milik pemilik berstatus dapat diubah; melempar 404/403/409 */
    private function lockOwnedEditable(Actor $actor, string $reportId): array
    {
        $row = $this->lockRow($reportId);
        if ($row === null || !$this->canView($row, $actor)) {
            throw ApiException::notFound('Laporan');
        }
        if ($row['user_id'] !== $actor->userId) {
            throw ApiException::forbidden('Hanya pemilik yang boleh mengubah laporan.');
        }
        if (!in_array($row['status'], self::EDITABLE, true)) {
            throw ApiException::conflict('Laporan tidak dapat diubah pada status ini.', 'not_editable');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function lockRow(string $reportId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT id, user_id, status::text AS status FROM reports WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $reportId]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '22P02') {                           // bukan UUID
                return null;
            }
            throw $e;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row */
    private function canView(array $row, Actor $viewer): bool
    {
        if ($viewer->userId !== null && $row['user_id'] === $viewer->userId) {
            return true;
        }
        if ($row['status'] === 'draft') {
            return false;
        }
        if (in_array($viewer->role, [Role::Volunteer, Role::Admin], true)) {
            return true;
        }

        return ReportStatus::from($row['status'])->isPublic();
    }

    /** Pemilik atau moderator. @param array<string, mixed> $row */
    private function isPrivileged(array $row, Actor $viewer): bool
    {
        return ($viewer->userId !== null && $row['user_id'] === $viewer->userId)
            || in_array($viewer->role, [Role::Volunteer, Role::Admin], true);
    }

    private const SELECT = "SELECT r.id, r.client_uuid, r.user_id, r.category_id, r.title, r.description, r.status::text AS status,
            r.parent_report_id, r.created_at, r.updated_at, r.created_at::text AS created_raw,
            l.lat, l.lon, l.address, l.source::text AS source, u.display_name AS reporter,
            (SELECT count(DISTINCT c.user_id) FROM confirmations c
              WHERE c.report_id = r.id AND c.type IN ('juga_melihat', 'masih_terjadi')) AS confirmations,
            EXISTS (SELECT 1 FROM action_claims a WHERE a.report_id = r.id AND a.state = 'active') AS active_claim,
            (SELECT h.reason FROM status_history h WHERE h.report_id = r.id AND h.reason IS NOT NULL ORDER BY h.id DESC LIMIT 1) AS last_reason
       FROM reports r
       JOIN report_locations l ON l.report_id = r.id
       JOIN users u ON u.id = r.user_id";

    /** @return array<string, mixed>|null */
    private function fetchRow(string $reportId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(self::SELECT . ' WHERE r.id = :id');
            $stmt->execute([':id' => $reportId]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '22P02') {
                return null;
            }
            throw $e;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    private function page(Actor $viewer, array $where, array $params, array $filters): array
    {
        if (($filters['category_id'] ?? null) !== null && $filters['category_id'] !== '') {
            if (filter_var($filters['category_id'], FILTER_VALIDATE_INT) === false) {
                throw ApiException::validation(['category_id' => 'category_id harus bilangan bulat.']);
            }
            $where[] = 'r.category_id = :cat';
            $params[':cat'] = (int) $filters['category_id'];
        }
        if (($filters['bbox'] ?? null) !== null && $filters['bbox'] !== '') {
            $parts = array_map('trim', explode(',', (string) $filters['bbox']));
            if (count($parts) !== 4 || array_filter($parts, static fn ($x): bool => !is_numeric($x)) !== []) {
                throw ApiException::validation(['bbox' => 'bbox harus "min_lon,min_lat,max_lon,max_lat".']);
            }
            [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
            if ($minLon > $maxLon || $minLat > $maxLat) {
                throw ApiException::validation(['bbox' => 'Nilai minimum tidak boleh melebihi maksimum.']);
            }
            $where[] = 'l.lat BETWEEN :minLat AND :maxLat AND l.lon BETWEEN :minLon AND :maxLon';
            $params += [':minLat' => $minLat, ':maxLat' => $maxLat, ':minLon' => $minLon, ':maxLon' => $maxLon];
        }
        if (($filters['cursor'] ?? null) !== null && $filters['cursor'] !== '') {
            $decoded = json_decode((string) base64_decode(strtr((string) $filters['cursor'], '-_', '+/'), true), true);
            if (!is_array($decoded) || count($decoded) !== 2 || !is_string($decoded[0]) || !is_string($decoded[1])
                || preg_match(self::UUID_RE, $decoded[1]) !== 1 || strtotime($decoded[0]) === false) {
                throw ApiException::validation(['cursor' => 'Kursor tidak valid.']);
            }
            $where[] = '(r.created_at, r.id) < (:ct::timestamptz, :ci::uuid)';
            $params[':ct'] = $decoded[0];
            $params[':ci'] = $decoded[1];
        }
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 20)));

        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . ($limit + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[$limit - 1];
            $next = rtrim(strtr(base64_encode((string) json_encode([$last['created_raw'], $last['id']])), '+/', '-_'), '=');
        }

        return ['items' => $this->present($rows, $viewer), 'next_cursor' => $next];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows, Actor $viewer): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, report_id FROM report_images WHERE report_id IN ($in) ORDER BY created_at, id");
        $stmt->execute($ids);
        $imgs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $i) {
            $imgs[$i['report_id']][] = ['id' => $i['id'], 'url' => sprintf($this->imageUrlTemplate, $i['id'])];
        }

        $out = [];
        foreach ($rows as $r) {
            $priv = $this->isPrivileged($r, $viewer);
            $owner = $viewer->userId !== null && $r['user_id'] === $viewer->userId;
            $item = [
                'id' => $r['id'],
                'status' => $r['status'],
                'category_id' => (int) $r['category_id'],
                'title' => $r['title'],
                'description' => $r['description'],
                'location' => ['lat' => (float) $r['lat'], 'lon' => (float) $r['lon'], 'address' => $r['address'], 'source' => $r['source']],
                'images' => $imgs[$r['id']] ?? [],
                'reporter_display_name' => $r['reporter'],
                'confirmation_count' => (int) $r['confirmations'],
                'active_claim' => (bool) $r['active_claim'],
                'parent_report_id' => $r['parent_report_id'],
                'created_at' => $this->iso($r['created_at']),
                'updated_at' => $this->iso($r['updated_at']),
            ];
            if ($owner) {
                $item['client_uuid'] = $r['client_uuid'];
            }
            // Alasan hanya relevan saat status meminta tindakan/menjelaskan penolakan; hanya untuk pemilik & moderator.
            if ($priv && in_array($r['status'], ['perlu_perbaikan', 'ditolak'], true)) {
                $item['reason'] = $r['last_reason'];
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function validate(array $in, bool $requireAll): array
    {
        $errors = [];
        $out = [];

        if (array_key_exists('category_id', $in) || $requireAll) {
            $cat = $in['category_id'] ?? null;
            if (!is_int($cat) && !(is_string($cat) && ctype_digit($cat))) {
                $errors['category_id'] = 'category_id wajib bilangan bulat.';
            } elseif ($this->scalar('SELECT 1 FROM categories WHERE id = :i AND active', [':i' => (int) $cat]) === false) {
                $errors['category_id'] = 'Kategori tidak ditemukan atau tidak aktif.';
            } else {
                $out['category_id'] = (int) $cat;
            }
        }
        if (array_key_exists('title', $in) || $requireAll) {
            $t = trim((string) ($in['title'] ?? ''));
            if (mb_strlen($t) < 3 || mb_strlen($t) > 150) {
                $errors['title'] = 'Judul harus 3 sampai 150 karakter.';
            } else {
                $out['title'] = $t;
            }
        }
        if (array_key_exists('description', $in) || $requireAll) {
            $out['description'] = trim((string) ($in['description'] ?? ''));
        }
        if (array_key_exists('location', $in) || $requireAll) {
            $l = $in['location'] ?? null;
            if (!is_array($l) || !is_numeric($l['lat'] ?? null) || !is_numeric($l['lon'] ?? null)
                || (float) $l['lat'] < -90 || (float) $l['lat'] > 90 || (float) $l['lon'] < -180 || (float) $l['lon'] > 180) {
                $errors['location'] = 'Lokasi wajib berisi lat (-90..90) dan lon (-180..180).';
            } elseif (!in_array($l['source'] ?? null, ['gps', 'manual'], true)) {
                $errors['location'] = 'source lokasi harus "gps" atau "manual".';
            } else {
                $out['location'] = [
                    'lat' => (float) $l['lat'], 'lon' => (float) $l['lon'],
                    'address' => isset($l['address']) && $l['address'] !== '' ? (string) $l['address'] : null,
                    'source' => $l['source'],
                ];
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $out;
    }

    /** @param array<string, mixed> $p */
    private function scalar(string $sql, array $p = []): mixed
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($p);

        return $s->fetchColumn();
    }

    private function iso(string $ts): string
    {
        return (new \DateTimeImmutable($ts))->format(\DATE_ATOM);
    }
}
