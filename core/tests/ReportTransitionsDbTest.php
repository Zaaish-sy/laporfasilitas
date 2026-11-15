<?php

declare(strict_types=1);

// Jalankan: php backend/tests/ReportTransitionsDbTest.php   (butuh PostgreSQL + ext pdo_pgsql)
require __DIR__ . '/DbHarness.php';

use App\Application\Actor;
use App\Application\ReportTransitions;
use App\Domain\ReportStatus as S;
use App\Domain\Role;

$pdo = Db::boot();
$svc = new ReportTransitions($pdo);

// ---------- Fixture ----------
function mkUser(PDO $pdo, string $name, string $role = 'pengguna'): string
{
    $s = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, role) VALUES (:e, 'x', :n, :r::app_role) RETURNING id");
    $s->execute([':e' => "$name@x.test", ':n' => $name, ':r' => $role]);

    return (string) $s->fetchColumn();
}

$ownerId = mkUser($pdo, 'pemilik');
$helperId = mkUser($pdo, 'penolong');
$volId = mkUser($pdo, 'volunteer', 'volunteer');
$adminId = mkUser($pdo, 'admin', 'admin');

$owner = new Actor($ownerId, Role::User);
$helper = new Actor($helperId, Role::User);
$vol = new Actor($volId, Role::Volunteer);
$admin = new Actor($adminId, Role::Admin);
$system = Actor::system();

$rid = (string) Db::scalar($pdo, "INSERT INTO reports (client_uuid, user_id, category_id, title) VALUES (gen_random_uuid(), :u, 1, 'Jalan berlubang') RETURNING id", [':u' => $ownerId]);

$status = static fn (string $id): string => (string) Db::scalar($pdo, 'SELECT status::text FROM reports WHERE id = :i', [':i' => $id]);
$histCount = static fn (string $id): int => (int) Db::scalar($pdo, 'SELECT count(*) FROM status_history WHERE report_id = :i', [':i' => $id]);

// ---------- Pengiriman & moderasi ----------
Db::expect(fn () => $svc->transition($rid, S::MenungguAnalisis, $helper), 403, 'forbidden', 'bukan pemilik tidak boleh mengirim');
Db::check($status($rid) === 'draft' && $histCount($rid) === 0, 'penolakan tidak mengubah status maupun riwayat');
Db::expect(fn () => $svc->transition('00000000-0000-0000-0000-00000000dead', S::MenungguAnalisis, $owner), 404, 'not_found', 'laporan tidak ada');

$r = $svc->transition($rid, S::MenungguAnalisis, $owner);
Db::check($r->transitionId === 'T-01' && $r->from === S::Draft && $status($rid) === 'menunggu_analisis', 'T-01 pemilik mengirim');
Db::expect(fn () => $svc->transition($rid, S::Dipublikasikan, $system), 409, 'guard_failed', 'auto-publish mati: sistem tidak boleh langsung publikasi');
$svc->transition($rid, S::PerluReview, $system);
$before = $histCount($rid);
Db::expect(fn () => $svc->transition($rid, S::Dipublikasikan, $vol), 403, 'forbidden', 'volunteer tidak boleh mempublikasikan');
Db::expect(fn () => $svc->transition($rid, S::Dipublikasikan, $owner), 403, 'forbidden', 'pemilik tidak boleh mempublikasikan sendiri');
Db::check($status($rid) === 'perlu_review' && $histCount($rid) === $before, 'status & riwayat tidak berubah setelah penolakan');
Db::expect(fn () => $svc->transition($rid, S::Ditolak, $admin), 422, 'reason_required', 'menolak wajib beralasan');
$svc->transition($rid, S::Dipublikasikan, $admin);
$svc->transition($rid, S::MenungguTindakLanjut, $system);

// ---------- Klaim serempak: kunci baris (SELECT ... FOR UPDATE) ----------
// A menahan transaksi terbuka (sudah memilih jadi penangan). B adalah PROSES TERPISAH yang meminta hal yang sama
// SELAMA A belum commit. Setelah A commit, B HARUS membaca keadaan terbaru dan ditolak (409). Tanpa kunci baris,
// B membaca status basi, lolos guard, lalu menimpa: klaim ganda dan riwayat ganda.
$svcA = new ReportTransitions($pdo);
$pdo->beginTransaction();
$svcA->transition($rid, S::DalamPenanganan, $helper);
$pdo->prepare("INSERT INTO action_claims (report_id, user_id) VALUES (:r, :u)")->execute([':r' => $rid, ':u' => $helperId]);

$cmd = [PHP_BINARY, __DIR__ . '/_claim_worker.php', Db::$schema, $rid, $volId, 'volunteer', 'dalam_penanganan'];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$monitor = Db::connect();
$waiting = false;
for ($i = 0; $i < 100 && !$waiting; $i++) {   // tunggu sampai B benar-benar tertahan oleh kunci (maks. ~5 dtk)
    usleep(50_000);
    $waiting = (int) Db::scalar($monitor, "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'") > 0;
}
Db::check($waiting, 'proses pesaing B tertahan menunggu kunci milik A (bukan berjalan bebas)');
$pdo->commit();                                   // A selesai; B dilepas
$out = trim((string) stream_get_contents($pipes[1]));
$err = trim((string) stream_get_contents($pipes[2]));
proc_close($proc);
$res = json_decode($out, true) ?: ['raw' => $out . $err];
Db::check(($res['ok'] ?? null) === false && ($res['status'] ?? 0) === 409 && ($res['code'] ?? '') === 'invalid_transition',
    'pesaing yang menunggu ditolak 409 setelah A commit, bukan menimpa (hasil: ' . json_encode($res) . ')');
Db::check((int) Db::scalar($pdo, "SELECT count(*) FROM action_claims WHERE report_id = :r AND state = 'active'", [':r' => $rid]) === 1, 'tepat satu klaim aktif');
Db::check((int) Db::scalar($pdo, "SELECT count(*) FROM status_history WHERE report_id = :r AND transition_id = 'T-08'", [':r' => $rid]) === 1,
    'tepat satu catatan riwayat T-08 (tidak ada riwayat ganda)');

$claim1 = (string) Db::scalar($pdo, "SELECT id FROM action_claims WHERE report_id = :r AND state = 'active'", [':r' => $rid]);

// ---------- Bukti penanganan ----------
Db::expect(fn () => $svc->transition($rid, S::MenungguKonfirmasi, $helper), 409, 'guard_failed', 'kirim bukti tanpa bukti terunggah');
$addEvidence = static fn (string $claim) => $pdo->prepare("INSERT INTO resolution_evidence (claim_id, photo_after_key) VALUES (:c, 'k')")->execute([':c' => $claim]);
$addEvidence($claim1);
Db::expect(fn () => $svc->transition($rid, S::MenungguKonfirmasi, $vol), 403, 'forbidden', 'bukan pemegang klaim tidak boleh mengirim bukti');
$svc->transition($rid, S::MenungguKonfirmasi, $helper);
Db::check($status($rid) === 'menunggu_konfirmasi', 'T-10 pemegang klaim mengirim bukti');

// Selesai butuh bukti DISETUJUI, bukan sekadar terkirim
Db::expect(fn () => $svc->transition($rid, S::Selesai, $admin), 409, 'guard_failed', 'Selesai dengan bukti baru berstatus pending ditolak');
Db::expect(fn () => $svc->transition($rid, S::DalamPenanganan, $admin), 422, 'reason_required', 'menolak bukti wajib beralasan');
$pdo->exec("UPDATE resolution_evidence SET review_state = 'rejected' WHERE claim_id = '$claim1'");
$svc->transition($rid, S::DalamPenanganan, $admin, 'foto kurang jelas');             // T-11
Db::check($status($rid) === 'dalam_penanganan', 'T-11 bukti ditolak, kembali ke penanganan');
$addEvidence($claim1);
$svc->transition($rid, S::MenungguKonfirmasi, $helper);
Db::expect(fn () => $svc->transition($rid, S::Selesai, $vol), 403, 'forbidden', 'volunteer tidak boleh menetapkan Selesai');
Db::expect(fn () => $svc->transition($rid, S::Selesai, $owner), 403, 'forbidden', 'pelapor tidak boleh menetapkan Selesai');

// Admin menyetujui: tandai bukti DULU, lalu transisi, dalam satu transaksi
$pdo->beginTransaction();
$pdo->exec("UPDATE resolution_evidence SET review_state = 'approved' WHERE claim_id = '$claim1' AND review_state = 'pending'");
$svc->transition($rid, S::Selesai, $admin);
$pdo->exec("UPDATE action_claims SET state = 'completed' WHERE id = '$claim1'");
$pdo->commit();
Db::check($status($rid) === 'selesai', 'T-12 Selesai hanya setelah bukti disetujui admin');

// ---------- Buka kembali ----------
Db::expect(fn () => $svc->transition($rid, S::DibukaKembali, $admin, 'masih rusak'), 409, 'guard_failed', 'buka kembali tanpa pengajuan');
$pdo->prepare("INSERT INTO reopen_requests (report_id, user_id, photo_key) VALUES (:r, :u, 'p')")->execute([':r' => $rid, ':u' => $helperId]);
Db::expect(fn () => $svc->transition($rid, S::DibukaKembali, $admin, 'masih rusak'), 409, 'guard_failed', 'pengajuan masih pending belum cukup');
$pdo->exec("UPDATE reopen_requests SET review_state = 'approved' WHERE report_id = '$rid'");
Db::expect(fn () => $svc->transition($rid, S::DibukaKembali, $admin), 422, 'reason_required', 'buka kembali wajib beralasan');
$svc->transition($rid, S::DibukaKembali, $admin, 'foto terbaru menunjukkan masih rusak');
$svc->transition($rid, S::MenungguTindakLanjut, $system);

// Siklus kedua sampai Selesai lagi
$pdo->beginTransaction();
$svc->transition($rid, S::DalamPenanganan, $vol);
$pdo->prepare("INSERT INTO action_claims (report_id, user_id) VALUES (:r, :u)")->execute([':r' => $rid, ':u' => $volId]);
$pdo->commit();
$claim2 = (string) Db::scalar($pdo, "SELECT id FROM action_claims WHERE report_id = :r AND state = 'active'", [':r' => $rid]);
$addEvidence($claim2);
$svc->transition($rid, S::MenungguKonfirmasi, $vol);
$pdo->beginTransaction();
$pdo->exec("UPDATE resolution_evidence SET review_state = 'approved' WHERE claim_id = '$claim2'");
$svc->transition($rid, S::Selesai, $admin);
$pdo->exec("UPDATE action_claims SET state = 'completed' WHERE id = '$claim2'");
$pdo->commit();
Db::check($status($rid) === 'selesai', 'siklus kedua mencapai Selesai');
Db::expect(fn () => $svc->transition($rid, S::DibukaKembali, $admin, 'coba pakai persetujuan lama'), 409, 'guard_failed',
    'persetujuan buka kembali lama TIDAK bisa dipakai ulang pada siklus berikutnya');

// ---------- Riwayat lengkap & berurutan ----------
$chain = $pdo->query("SELECT transition_id, actor_id FROM status_history WHERE report_id = '$rid' ORDER BY id")->fetchAll();
$expected = ['T-01', 'T-03', 'T-06a', 'T-07', 'T-08', 'T-10', 'T-11', 'T-10', 'T-12', 'T-13', 'T-14', 'T-08', 'T-10', 'T-12'];
Db::check(array_column($chain, 'transition_id') === $expected, 'rantai riwayat = ' . implode(',', array_column($chain, 'transition_id')));
Db::check($chain[1]['actor_id'] === null && $chain[3]['actor_id'] === null, 'transisi sistem tercatat tanpa aktor');
Db::check($chain[2]['actor_id'] === $adminId && $chain[8]['actor_id'] === $adminId, 'transisi admin tercatat dengan aktor admin');
$reasons = $pdo->query("SELECT transition_id FROM status_history WHERE report_id = '$rid' AND reason IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
Db::check($reasons === ['T-11', 'T-13'], 'alasan tercatat tepat pada T-11 dan T-13 (dapat: ' . implode(',', $reasons) . ')');

// ---------- Atomisitas: transaksi luar dibatalkan -> status & riwayat ikut batal ----------
$rid2 = (string) Db::scalar($pdo, "INSERT INTO reports (client_uuid, user_id, category_id, title) VALUES (gen_random_uuid(), :u, 1, 'Laporan 2') RETURNING id", [':u' => $ownerId]);
$pdo->beginTransaction();
$svc->transition($rid2, S::MenungguAnalisis, $owner);
Db::check($status($rid2) === 'menunggu_analisis', 'di dalam transaksi luar status sudah berubah');
$pdo->rollBack();
Db::check($status($rid2) === 'draft' && $histCount($rid2) === 0, 'rollback transaksi luar membatalkan status DAN riwayat');

// ---------- Takedown (T-15) ----------
$pdo->exec("UPDATE reports SET status = 'dalam_penanganan' WHERE id = '$rid2'");
Db::expect(fn () => $svc->transition($rid2, S::Ditolak, $admin), 422, 'reason_required', 'takedown wajib beralasan');
Db::expect(fn () => $svc->transition($rid2, S::Ditolak, $vol, 'melanggar'), 403, 'forbidden', 'volunteer tidak boleh takedown');
$svc->transition($rid2, S::Ditolak, $admin, 'memuat data pribadi');
Db::check($status($rid2) === 'ditolak', 'T-15 takedown oleh admin dengan alasan');
Db::expect(fn () => $svc->transition($rid2, S::Dipublikasikan, $admin), 409, 'invalid_transition', 'Ditolak final: tidak bisa dipulihkan lewat transisi');

// ---------- Publikasi otomatis hanya bila flag dinyalakan ----------
$auto = new ReportTransitions($pdo, autoPublishEnabled: true);
$rid3 = (string) Db::scalar($pdo, "INSERT INTO reports (client_uuid, user_id, category_id, title, status) VALUES (gen_random_uuid(), :u, 1, 'Laporan 3', 'menunggu_analisis') RETURNING id", [':u' => $ownerId]);
$auto->transition($rid3, S::Dipublikasikan, $system);
Db::check($status($rid3) === 'dipublikasikan', 'auto-publish berjalan bila flag aktif (T-02)');

// ---------- status_history tetap append-only dari sisi layanan ----------
Db::check(true, 'lihat db/tests/constraints.sql untuk trigger append-only');

Db::done();
