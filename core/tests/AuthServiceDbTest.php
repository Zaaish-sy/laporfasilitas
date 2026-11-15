<?php

declare(strict_types=1);

// Jalankan: php backend/tests/AuthServiceDbTest.php   (butuh PostgreSQL + ext pdo_pgsql)
require __DIR__ . '/DbHarness.php';

use App\Application\AuthService;

$pdo = Db::boot();
$auth = new AuthService($pdo);

// --- Registrasi ---
Db::expect(fn () => $auth->register('bukan-email', 'password123', 'Ani'), 422, 'validation_failed', 'email tidak valid');
Db::expect(fn () => $auth->register('a@x.test', 'pendek', 'Ani'), 422, 'validation_failed', 'password terlalu pendek');
Db::expect(fn () => $auth->register('a@x.test', 'password123', 'A'), 422, 'validation_failed', 'nama terlalu pendek');

$reg = $auth->register('  Ani@Contoh.Test ', 'password123', 'Ani');
Db::check($reg['user']['email'] === 'ani@contoh.test', 'email dinormalisasi huruf kecil');
Db::check($reg['user']['email_verified'] === false, 'akun baru belum terverifikasi');
Db::check($reg['user']['role'] === 'pengguna', 'peran bawaan pengguna');
Db::check(!array_key_exists('password_hash', $reg['user']), 'hash password tidak bocor ke keluaran');
Db::expect(fn () => $auth->register('ANI@contoh.test', 'password123', 'Ani 2'), 409, 'duplicate', 'email ganda (tak peka huruf)');
$userId = $reg['user']['id'];

// Token verifikasi hanya tersimpan sebagai hash
Db::check(Db::scalar($pdo, 'SELECT count(*) FROM email_verifications WHERE token_hash = :t', [':t' => $reg['verification_token']]) === 0,
    'token verifikasi mentah tidak tersimpan');

// --- Verifikasi email ---
Db::expect(fn () => $auth->verifyEmail('salah'), 422, 'validation_failed', 'token verifikasi salah');
$auth->verifyEmail($reg['verification_token']);
Db::check($auth->authenticate('x') === null, 'authenticate token ngawur -> null');
Db::expect(fn () => $auth->verifyEmail($reg['verification_token']), 422, 'validation_failed', 'token verifikasi tidak bisa dipakai dua kali');
Db::check($auth->resendVerification('ani@contoh.test') === null, 'resend untuk akun terverifikasi -> null');
Db::check($auth->resendVerification('tidak-ada@contoh.test') === null, 'resend untuk email asing -> null (tak membocorkan)');

$kedua = $auth->register('budi@contoh.test', 'password123', 'Budi');
$resend = $auth->resendVerification('budi@contoh.test');
Db::check(is_string($resend) && $resend !== $kedua['verification_token'], 'resend akun belum terverifikasi menerbitkan token baru');
$pdo->exec("UPDATE email_verifications SET expires_at = now() - interval '1 minute'");
Db::expect(fn () => $auth->verifyEmail($resend), 422, 'validation_failed', 'token verifikasi kedaluwarsa');

// --- Login ---
$msgUnknown = $msgWrong = '';
try { $auth->login('tidak-ada@contoh.test', 'password123'); } catch (\App\Application\ApiException $e) { $msgUnknown = $e->getMessage(); }
try { $auth->login('ani@contoh.test', 'salah-salah'); } catch (\App\Application\ApiException $e) { $msgWrong = $e->getMessage(); }
Db::check($msgUnknown !== '' && $msgUnknown === $msgWrong, 'pesan galat sama untuk email tak dikenal dan password salah');
Db::expect(fn () => $auth->login('ani@contoh.test', 'salah-salah'), 401, 'unauthorized', 'login password salah');

$t1 = $auth->login('ANI@contoh.test', 'password123');
Db::check($t1['expires_in'] === 3600, 'expires_in = TTL access');
Db::check(Db::scalar($pdo, 'SELECT count(*) FROM api_tokens WHERE token_hash IN (:a, :b)', [':a' => $t1['access_token'], ':b' => $t1['refresh_token']]) === 0,
    'token mentah tidak tersimpan (hanya hash)');
Db::check(Db::scalar($pdo, 'SELECT count(*) FROM api_tokens WHERE user_id = :u', [':u' => $userId]) === 2, 'satu login = dua token');

$me = $auth->authenticate($t1['access_token']);
Db::check($me !== null && $me['id'] === $userId && $me['email_verified'] === true, 'access token mengautentikasi pemiliknya');
Db::check($auth->authenticate($t1['refresh_token']) === null, 'refresh token TIDAK bisa dipakai sebagai access token');

// --- Refresh (rotasi) ---
$t2 = $auth->refresh($t1['refresh_token']);
Db::check($t2['access_token'] !== $t1['access_token'], 'refresh menerbitkan token baru');
Db::check($auth->authenticate($t1['access_token']) === null, 'access lama dicabut setelah refresh');
Db::expect(fn () => $auth->refresh($t1['refresh_token']), 401, 'unauthorized', 'refresh token lama tidak bisa dipakai ulang');
Db::check($auth->authenticate($t2['access_token']) !== null, 'access baru berlaku');
Db::expect(fn () => $auth->refresh($t2['access_token']), 401, 'unauthorized', 'access token tidak bisa dipakai untuk refresh');

// --- Kedaluwarsa ---
$pdo->exec("UPDATE api_tokens SET expires_at = now() - interval '1 second' WHERE kind = 'access' AND token_hash = '" . hash('sha256', $t2['access_token']) . "'");
Db::check($auth->authenticate($t2['access_token']) === null, 'access token kedaluwarsa ditolak');

// --- Logout ---
$t3 = $auth->login('ani@contoh.test', 'password123');
$auth->logout($t3['access_token']);
Db::check($auth->authenticate($t3['access_token']) === null, 'logout mencabut access');
Db::expect(fn () => $auth->refresh($t3['refresh_token']), 401, 'unauthorized', 'logout mencabut refresh pasangannya');
$auth->logout($t3['access_token']);
$auth->logout('token-tidak-ada');
Db::check(true, 'logout idempoten');

// --- Profil ---
Db::expect(fn () => $auth->updateProfile($userId, 'X'), 422, 'validation_failed', 'nama profil terlalu pendek');
Db::check($auth->updateProfile($userId, '  Ani Baru ')['display_name'] === 'Ani Baru', 'ubah nama tampilan');

// --- Ganti password: cabut sesi lain, pertahankan sesi sekarang ---
$sesiA = $auth->login('ani@contoh.test', 'password123');
$sesiB = $auth->login('ani@contoh.test', 'password123');
Db::expect(fn () => $auth->changePassword($userId, 'salah', 'passwordbaru1', $sesiA['access_token']), 422, 'validation_failed', 'ganti password: password lama salah');
Db::expect(fn () => $auth->changePassword($userId, 'password123', 'pendek', $sesiA['access_token']), 422, 'validation_failed', 'ganti password: baru terlalu pendek');
$auth->changePassword($userId, 'password123', 'passwordbaru1', $sesiA['access_token']);
Db::check($auth->authenticate($sesiA['access_token']) !== null, 'sesi saat ini dipertahankan');
Db::check($auth->authenticate($sesiB['access_token']) === null, 'sesi lain dicabut');
Db::expect(fn () => $auth->login('ani@contoh.test', 'password123'), 401, 'unauthorized', 'password lama tak berlaku');
Db::check(isset($auth->login('ani@contoh.test', 'passwordbaru1')['access_token']), 'password baru berlaku');

// --- Dinonaktifkan admin ---
$off = $auth->login('ani@contoh.test', 'passwordbaru1');
$pdo->prepare('UPDATE users SET disabled_at = now() WHERE id = :u')->execute([':u' => $userId]);
Db::check($auth->authenticate($off['access_token']) === null, 'akun nonaktif: token tidak berlaku');
Db::expect(fn () => $auth->login('ani@contoh.test', 'passwordbaru1'), 401, 'unauthorized', 'akun nonaktif tidak bisa login');
Db::expect(fn () => $auth->refresh($off['refresh_token']), 401, 'unauthorized', 'akun nonaktif tidak bisa refresh');
$pdo->prepare('UPDATE users SET disabled_at = NULL WHERE id = :u')->execute([':u' => $userId]);

// --- Hapus akun: anonimisasi, riwayat tetap utuh ---
$pdo->exec("INSERT INTO reports (id, client_uuid, user_id, category_id, title) VALUES
    ('10000000-0000-0000-0000-0000000000aa', gen_random_uuid(), '$userId', 1, 'Laporan lama')");
$pdo->exec("INSERT INTO status_history (report_id, to_status, actor_id) VALUES
    ('10000000-0000-0000-0000-0000000000aa', 'draft', '$userId')");
$live = $auth->login('ani@contoh.test', 'passwordbaru1');
$auth->deleteAccount($userId);
$row = $pdo->query("SELECT email, display_name, password_hash, deleted_at FROM users WHERE id = '$userId'")->fetch();
Db::check($row['deleted_at'] !== null && $row['display_name'] === 'Akun dihapus', 'akun dianonimkan');
Db::check(!str_contains($row['email'], 'contoh.test'), 'email asli tidak tersisa');
Db::check($auth->authenticate($live['access_token']) === null, 'token akun terhapus dicabut');
Db::expect(fn () => $auth->login('ani@contoh.test', 'passwordbaru1'), 401, 'unauthorized', 'akun terhapus tidak bisa login');
Db::check(Db::scalar($pdo, "SELECT count(*) FROM reports WHERE user_id = '$userId'") === 1, 'laporan tetap ada setelah akun dihapus');
Db::check(Db::scalar($pdo, "SELECT count(*) FROM status_history WHERE actor_id = '$userId'") === 1, 'riwayat status tetap utuh');
$again = $auth->register('ani@contoh.test', 'password123', 'Ani Lagi');
Db::check($again['user']['id'] !== $userId, 'email lama bisa didaftarkan ulang setelah penghapusan');

Db::done();
