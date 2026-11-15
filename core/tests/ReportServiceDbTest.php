<?php

declare(strict_types=1);

// Jalankan: php core/tests/ReportServiceDbTest.php   (butuh PostgreSQL + ext pdo_pgsql)
require __DIR__ . '/DbHarness.php';

use App\Application\Actor;
use App\Application\ReportService;
use App\Application\ReportTransitions;
use App\Domain\Role;

$pdo = Db::boot();
$transitions = new ReportTransitions($pdo);
$svc = new ReportService($pdo, $transitions, maxImagesPerReport: 2);

function mkUser(PDO $pdo, string $name, string $role = 'pengguna', bool $verified = true): string
{
    $s = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, role, email_verified_at)
        VALUES (:e, 'x', :n, :r::app_role, CASE WHEN :v THEN now() END) RETURNING id");
    $s->execute([':e' => "$name@x.test", ':n' => $name, ':r' => $role, ':v' => $verified ? 'true' : 'false']);

    return (string) $s->fetchColumn();
}

$ownerId = mkUser($pdo, 'pemilik');
$otherId = mkUser($pdo, 'lain');
$volId = mkUser($pdo, 'vol', 'volunteer');
$adminId = mkUser($pdo, 'adm', 'admin');
$owner = new Actor($ownerId, Role::User);
$other = new Actor($otherId, Role::User);
$vol = new Actor($volId, Role::Volunteer);
$admin = new Actor($adminId, Role::Admin);

const U1 = '11111111-1111-4111-8111-111111111111';
$valid = [
    'client_uuid' => U1, 'category_id' => 1, 'title' => 'Jalan berlubang', 'description' => 'Dekat pasar',
    'location' => ['lat' => -0.5, 'lon' => 117.15, 'address' => 'Jl. Contoh', 'source' => 'gps'],
];
$with = static fn (array $over): array => array_replace_recursive($valid, $over);

// ---------- Validasi ----------
Db::expect(fn () => $svc->createDraft($owner, $with(['client_uuid' => 'bukan-uuid'])), 422, 'validation_failed', 'client_uuid bukan UUID');
Db::expect(fn () => $svc->createDraft($owner, $with(['category_id' => 999])), 422, 'validation_failed', 'kategori tidak ada');
Db::expect(fn () => $svc->createDraft($owner, $with(['title' => 'ab'])), 422, 'validation_failed', 'judul terlalu pendek');
Db::expect(fn () => $svc->createDraft($owner, $with(['location' => ['lat' => 95]])), 422, 'validation_failed', 'lat di luar rentang');
Db::expect(fn () => $svc->createDraft($owner, $with(['location' => ['source' => 'tebakan']])), 422, 'validation_failed', 'source lokasi tidak dikenal');
$noLoc = $valid;
unset($noLoc['location']);
Db::expect(fn () => $svc->createDraft($owner, $noLoc), 422, 'validation_failed', 'lokasi wajib');
$pdo->exec("INSERT INTO categories (name, active) VALUES ('Nonaktif', false)");
$inactive = (int) Db::scalar($pdo, "SELECT id FROM categories WHERE name = 'Nonaktif'");
Db::expect(fn () => $svc->createDraft($owner, $with(['category_id' => $inactive])), 422, 'validation_failed', 'kategori nonaktif ditolak');
Db::check(!in_array('Nonaktif', array_column($svc->listCategories(), 'name'), true), 'daftar kategori hanya yang aktif');
Db::expect(fn () => $svc->createDraft($admin, $valid), 403, 'forbidden', 'admin tidak membuat laporan (A-11)');
Db::check((int) Db::scalar($pdo, 'SELECT count(*) FROM reports') === 0, 'penolakan validasi tidak meninggalkan baris laporan');

// ---------- Membuat draft + idempotensi ----------
$r = $svc->createDraft($owner, $valid);
$rep = $r['report'];
$rid = $rep['id'];
Db::check($r['created'] === true && $rep['status'] === 'draft', 'draft dibuat berstatus draft');
Db::check($rep['location']['lat'] === -0.5 && $rep['location']['source'] === 'gps', 'lokasi tersimpan');
Db::check($rep['client_uuid'] === U1 && $rep['reporter_display_name'] === 'pemilik', 'pemilik melihat client_uuid dan nama tampilan');
Db::check(!array_key_exists('email', $rep), 'email pelapor tidak bocor');
$hist = $pdo->query("SELECT from_status, to_status, actor_id FROM status_history WHERE report_id = '$rid'")->fetchAll();
Db::check(count($hist) === 1 && $hist[0]['from_status'] === null && $hist[0]['to_status'] === 'draft' && $hist[0]['actor_id'] === $ownerId,
    'pembuatan tercatat di riwayat (dari NULL ke draft)');

$again = $svc->createDraft($owner, $with(['title' => 'Judul berbeda sama sekali']));
Db::check($again['created'] === false && $again['report']['id'] === $rid && $again['report']['title'] === 'Jalan berlubang',
    'client_uuid sama mengembalikan laporan lama, masukan baru diabaikan');
Db::check((int) Db::scalar($pdo, 'SELECT count(*) FROM reports') === 1, 'tidak ada laporan ganda');
$others = $svc->createDraft($other, $valid);
Db::check($others['created'] === true && $others['report']['id'] !== $rid, 'client_uuid sama dari pengguna lain = laporan terpisah');
$otherRid = $others['report']['id'];

// ---------- Visibilitas draft ----------
Db::expect(fn () => $svc->get($other, $rid), 404, 'not_found', 'pengguna lain tidak melihat draft');
Db::expect(fn () => $svc->get($vol, $rid), 404, 'not_found', 'volunteer tidak melihat draft milik orang lain');
Db::expect(fn () => $svc->get($admin, $rid), 404, 'not_found', 'admin tidak melihat draft milik orang lain');
Db::expect(fn () => $svc->get($owner, 'bukan-uuid'), 404, 'not_found', 'id bukan UUID -> 404, bukan galat server');
Db::check($svc->get($owner, $rid)['id'] === $rid, 'pemilik melihat draft sendiri');

// ---------- Mengubah draft ----------
Db::expect(fn () => $svc->updateDraft($other, $rid, ['title' => 'Retas']), 404, 'not_found', 'non-pemilik atas draft tak terlihat -> 404');
$u = $svc->updateDraft($owner, $rid, ['title' => '  Jalan berlubang parah ', 'location' => ['lat' => -0.51, 'lon' => 117.16, 'source' => 'manual']]);
Db::check($u['title'] === 'Jalan berlubang parah' && $u['location']['lat'] === -0.51 && $u['location']['source'] === 'manual', 'draft diubah (judul dirapikan, lokasi diganti)');
Db::check($u['description'] === 'Dekat pasar', 'field yang tidak dikirim tidak berubah');
Db::expect(fn () => $svc->updateDraft($owner, $rid, ['title' => 'x']), 422, 'validation_failed', 'ubah dengan judul tidak valid');

// ---------- Foto ----------
$sha1 = str_repeat('a', 64);
$sha2 = str_repeat('b', 64);
$sha3 = str_repeat('c', 64);
Db::expect(fn () => $svc->addImage($other, $rid, 'k/x', $sha1), 404, 'not_found', 'non-pemilik tidak bisa menambah foto');
Db::expect(fn () => $svc->addImage($owner, $rid, 'k/x', 'pendek'), 422, 'validation_failed', 'sha256 tidak valid');
$i1 = $svc->addImage($owner, $rid, 'k/1', $sha1);
$i1b = $svc->addImage($owner, $rid, 'k/1-ulang', $sha1);
Db::check($i1['created'] === true && $i1b['created'] === false && $i1['image']['id'] === $i1b['image']['id'], 'unggah foto sama idempoten');
Db::check(str_starts_with($i1['image']['url'], '/api/v1/images/'), 'url foto lewat endpoint terautentikasi');
$svc->addImage($owner, $rid, 'k/2', $sha2);
Db::expect(fn () => $svc->addImage($owner, $rid, 'k/3', $sha3), 422, 'validation_failed', 'melebihi batas jumlah foto');
Db::check($svc->addImage($owner, $rid, 'k/1-lagi', $sha1)['created'] === false, 'foto yang sudah ada tetap boleh diulang saat batas penuh');

// ---------- Kirim ----------
Db::expect(fn () => $svc->submit($other, $rid), 404, 'not_found', 'non-pemilik tidak bisa mengirim (draft tak terlihat)');
Db::expect(fn () => $svc->submit($owner, $otherRid), 404, 'not_found', 'tidak bisa mengirim draft orang lain');
Db::expect(fn () => $svc->submit($other, $otherRid), 422, 'validation_failed', 'kirim tanpa foto ditolak');
$sub = $svc->submit($owner, $rid);
Db::check($sub['status'] === 'menunggu_analisis', 'submit: draft -> menunggu_analisis');
Db::expect(fn () => $svc->submit($owner, $rid), 409, 'invalid_transition', 'kirim dua kali ditolak');
Db::expect(fn () => $svc->updateDraft($owner, $rid, ['title' => 'Ubah setelah kirim']), 409, 'not_editable', 'tidak bisa mengubah setelah dikirim');
Db::expect(fn () => $svc->addImage($owner, $rid, 'k/9', $sha3), 409, 'not_editable', 'tidak bisa menambah foto setelah dikirim');
$chain = $pdo->query("SELECT transition_id FROM status_history WHERE report_id = '$rid' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
Db::check($chain === [null, 'T-01'], 'riwayat: pembuatan lalu T-01');
Db::expect(fn () => $svc->get($other, $rid), 404, 'not_found', 'laporan menunggu_analisis belum publik');

// ---------- Daftar publik + paginasi kursor ----------
$mkPublic = static function (string $title, int $cat, float $lat, float $lon, string $status, string $createdAt) use ($pdo, $ownerId): string {
    $id = (string) Db::scalar($pdo, "INSERT INTO reports (client_uuid, user_id, category_id, title, status, created_at)
        VALUES (gen_random_uuid(), :u, :c, :t, :s::report_status, :ca) RETURNING id",
        [':u' => $ownerId, ':c' => $cat, ':t' => $title, ':s' => $status, ':ca' => $createdAt]);
    $pdo->prepare("INSERT INTO report_locations (report_id, lat, lon, source) VALUES (:r, :la, :lo, 'gps')")->execute([':r' => $id, ':la' => $lat, ':lo' => $lon]);

    return $id;
};
$p1 = $mkPublic('PUB1', 1, -0.50, 117.10, 'dipublikasikan', '2026-11-01 10:00:00+00');
$p2 = $mkPublic('PUB2', 1, -0.51, 117.11, 'menunggu_tindak_lanjut', '2026-11-02 10:00:00+00');
$p3 = $mkPublic('PUB3', 2, -0.52, 117.12, 'dalam_penanganan', '2026-11-03 10:00:00+00');
$p4 = $mkPublic('PUB4', 2, -0.53, 117.13, 'selesai', '2026-11-04 10:00:00+00');
$p5 = $mkPublic('PUB5', 3, -0.54, 117.14, 'dipublikasikan', '2026-11-05 10:00:00+00');
$p6 = $mkPublic('Tersembunyi', 1, -0.5, 117.1, 'perlu_review', '2026-11-06 10:00:00+00');
$p7 = $mkPublic('Ditolak', 1, -0.5, 117.1, 'ditolak', '2026-11-07 10:00:00+00');

$all = $svc->listPublic($other);
$titles = array_column($all['items'], 'title');
Db::check($titles === ['PUB5', 'PUB4', 'PUB3', 'PUB2', 'PUB1'], 'daftar publik: hanya status publik, terbaru dulu (dapat: ' . implode(',', $titles) . ')');
Db::check($all['next_cursor'] === null, 'tanpa sisa -> next_cursor null');

$seen = [];
$cursor = null;
$pages = 0;
do {
    $page = $svc->listPublic($other, ['limit' => 2, 'cursor' => $cursor]);
    $seen = array_merge($seen, array_column($page['items'], 'title'));
    $cursor = $page['next_cursor'];
    $pages++;
} while ($cursor !== null && $pages < 10);
Db::check($seen === ['PUB5', 'PUB4', 'PUB3', 'PUB2', 'PUB1'] && $pages === 3, "paginasi kursor: tanpa duplikat dan tanpa celah, {$pages} halaman");
Db::check(count($svc->listPublic($other, ['limit' => 999])['items']) === 5, 'limit di atas 50 dijepit, tidak galat');
Db::check(array_column($svc->listPublic($other, ['category_id' => 2])['items'], 'title') === ['PUB4', 'PUB3'], 'filter kategori');
Db::check(array_column($svc->listPublic($other, ['status' => 'selesai'])['items'], 'title') === ['PUB4'], 'filter status publik');
Db::expect(fn () => $svc->listPublic($other, ['status' => 'perlu_review']), 422, 'validation_failed', 'filter status non-publik ditolak');
Db::check(array_column($svc->listPublic($other, ['bbox' => '117.115,-0.545,117.145,-0.515'])['items'], 'title') === ['PUB5', 'PUB4', 'PUB3'], 'filter bbox');
Db::expect(fn () => $svc->listPublic($other, ['bbox' => 'ngawur']), 422, 'validation_failed', 'bbox tidak valid');
Db::expect(fn () => $svc->listPublic($other, ['bbox' => '5,0,1,1']), 422, 'validation_failed', 'bbox min > max');
Db::expect(fn () => $svc->listPublic($other, ['cursor' => 'bukan-kursor']), 422, 'validation_failed', 'kursor tidak valid');
Db::expect(fn () => $svc->listPublic($other, ['category_id' => 'x']), 422, 'validation_failed', 'category_id bukan angka');
Db::check(!array_key_exists('client_uuid', $all['items'][0]), 'daftar publik tidak membocorkan client_uuid milik orang lain');

// ---------- Riwayat saya ----------
$mine = $svc->listMine($owner);
Db::check(count($mine['items']) === 8 && !in_array('Jalan berlubang parah', array_column($svc->listMine($other)['items'], 'title'), true),
    'laporan saya: semua status milik sendiri, tidak termasuk milik orang lain (jumlah ' . count($mine['items']) . ')');
Db::check(array_column($svc->listMine($owner, ['status' => 'draft'])['items'], 'id') === [], 'filter status pada laporan saya (tidak ada draft tersisa)');
Db::check(array_column($svc->listMine($other, ['status' => 'draft'])['items'], 'id') === [$otherRid], 'laporan saya: draft sendiri terlihat');
Db::expect(fn () => $svc->listMine($owner, ['status' => 'ngawur']), 422, 'validation_failed', 'status laporan saya tidak dikenal');
Db::expect(fn () => $svc->listMine($admin), 403, 'forbidden', 'admin tidak punya daftar laporan sendiri');

// ---------- Detail: konfirmasi, klaim, alasan ----------
$pdo->exec("INSERT INTO users (id, email, password_hash, display_name) VALUES
    ('aaaaaaaa-0000-4000-8000-000000000001', 'k1@x.test', 'x', 'k1'),
    ('aaaaaaaa-0000-4000-8000-000000000002', 'k2@x.test', 'x', 'k2')");
$pdo->exec("INSERT INTO confirmations (report_id, user_id, type) VALUES
    ('$p1', 'aaaaaaaa-0000-4000-8000-000000000001', 'juga_melihat'),
    ('$p1', 'aaaaaaaa-0000-4000-8000-000000000001', 'masih_terjadi'),
    ('$p1', 'aaaaaaaa-0000-4000-8000-000000000002', 'masih_terjadi'),
    ('$p1', '$otherId', 'tampak_diperbaiki')");
Db::check($svc->get($other, $p1)['confirmation_count'] === 2, 'confirmation_count = pengguna berbeda (juga_melihat/masih_terjadi), tampak_diperbaiki tidak dihitung');
Db::check($svc->get($other, $p1)['active_claim'] === false, 'active_claim awalnya false');
$pdo->exec("INSERT INTO action_claims (report_id, user_id) VALUES ('$p1', '$otherId')");
Db::check($svc->get($other, $p1)['active_claim'] === true, 'active_claim true saat ada klaim aktif');

$pf = $mkPublic('Perlu diperbaiki', 1, -0.5, 117.1, 'perlu_perbaikan', '2026-11-08 10:00:00+00');
$pdo->exec("INSERT INTO status_history (report_id, from_status, to_status, transition_id, reason) VALUES ('$pf', 'perlu_review', 'perlu_perbaikan', 'T-06b', 'Foto terlalu gelap')");
Db::check(($svc->get($owner, $pf)['reason'] ?? null) === 'Foto terlalu gelap', 'pemilik melihat alasan permintaan perbaikan');
Db::check(($svc->get($admin, $pf)['reason'] ?? null) === 'Foto terlalu gelap', 'admin melihat alasan');
Db::expect(fn () => $svc->get($other, $pf), 404, 'not_found', 'perlu_perbaikan belum publik bagi pengguna lain');
$pdo->exec("UPDATE reports SET status = 'dipublikasikan' WHERE id = '$pf'");
Db::check(!array_key_exists('reason', $svc->get($other, $pf)), 'alasan moderasi tidak tampil ke publik');

// ---------- Riwayat status: alasan hanya untuk pemilik/moderator ----------
$pdo->exec("INSERT INTO status_history (report_id, from_status, to_status, transition_id, actor_id, reason) VALUES ('$p1', 'perlu_review', 'dipublikasikan', 'T-06a', '$adminId', 'catatan internal')");
$hOwner = $svc->history($owner, $p1);
$hPub = $svc->history($other, $p1);
Db::check(end($hOwner)['reason'] === 'catatan internal' && end($hOwner)['actor_display_name'] === 'adm' && end($hOwner)['transition_id'] === 'T-06a',
    'pemilik melihat alasan, pelaku, dan ID transisi');
Db::check(end($hPub)['reason'] === null && end($hPub)['actor_display_name'] === null && end($hPub)['transition_id'] === null,
    'pengguna lain tidak melihat alasan/pelaku/ID transisi');
Db::expect(fn () => $svc->history($other, $rid), 404, 'not_found', 'riwayat laporan tak terlihat -> 404');

// ---------- Hasil AI: hanya pemilik & moderator ----------
$imgP1 = (string) Db::scalar($pdo, "INSERT INTO report_images (report_id, storage_key, sha256) VALUES ('$p1', 'k/p1', :h) RETURNING id", [':h' => str_repeat('d', 64)]);
$pdo->exec("INSERT INTO ai_analyses (image_id, predicted_class, confidence, category_match, image_quality, verification_result, model_version, threshold_version)
    VALUES ('$imgP1', 'jalan', 0.8, true, 'ok', 'needs_review', 'm1', 't1')");
Db::check(count($svc->analysis($owner, $p1)) === 1 && $svc->analysis($vol, $p1)[0]['verification_result'] === 'needs_review', 'pemilik dan volunteer melihat hasil AI');
Db::expect(fn () => $svc->analysis($other, $p1), 403, 'forbidden', 'pengguna lain tidak melihat hasil AI laporan publik');

// ---------- Foto lewat endpoint terautentikasi ----------
Db::check($svc->imageStorageKey($other, $imgP1) === 'k/p1', 'foto laporan publik boleh dilihat pengguna lain');
$imgDraft = $i1['image']['id'];
$pdo->exec("UPDATE reports SET status = 'draft' WHERE id = '$rid'");
Db::expect(fn () => $svc->imageStorageKey($other, $imgDraft), 404, 'not_found', 'foto draft orang lain -> 404');
Db::check($svc->imageStorageKey($owner, $imgDraft) === 'k/1', 'pemilik melihat foto draft sendiri');
Db::expect(fn () => $svc->imageStorageKey($owner, 'bukan-uuid'), 404, 'not_found', 'id foto bukan UUID -> 404');

// ---------- Verifikasi email diwajibkan (opsional) ----------
$strict = new ReportService($pdo, $transitions, requireEmailVerification: true);
$unverified = new Actor(mkUser($pdo, 'belum', 'pengguna', false), Role::User);
Db::expect(fn () => $strict->createDraft($unverified, $with(['client_uuid' => '22222222-2222-4222-8222-222222222222'])), 403, 'email_not_verified', 'email belum terverifikasi ditolak bila syarat aktif');
Db::check($strict->createDraft($owner, $with(['client_uuid' => '33333333-3333-4333-8333-333333333333']))['created'] === true, 'akun terverifikasi lolos');
Db::check($svc->createDraft($unverified, $with(['client_uuid' => '44444444-4444-4444-8444-444444444444']))['created'] === true, 'syarat verifikasi mati: akun belum terverifikasi boleh (uji tertutup)');

Db::done();
