<?php

declare(strict_types=1);

namespace App\Application;

/**
 * Layanan akun & token (UC-01, UC-02). Murni PDO + PostgreSQL agar bisa diuji tanpa Laravel.
 * Controller Laravel hanya memanggil kelas ini (lihat laravel/).
 *
 * Keputusan yang masih terbuka (#18): masa berlaku token. Nilai bawaan di sini hanya usulan.
 *  - Token bersifat opak; yang disimpan di DB hanya hash sha256-nya.
 *  - Refresh token dirotasi: setiap refresh mencabut pasangan lama dan menerbitkan pasangan baru.
 */
final class AuthService
{
    private const DUMMY_HASH = '$2y$10$WXerKo0asuQ4MzhD19kMnOiiMi/5sFFpJDTJd9EBqoOUmhrewJb.q';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly int $accessTtlSeconds = 3600,
        private readonly int $refreshTtlSeconds = 2592000,
        private readonly int $verifyTtlSeconds = 86400,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{user: array<string, mixed>, verification_token: string}
     *         verification_token dikirim lewat email oleh pemanggil; jangan dikembalikan ke klien.
     */
    public function register(string $email, string $password, string $displayName): array
    {
        $email = $this->normalizeEmail($email);
        $displayName = trim($displayName);

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Format email tidak valid.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password minimal 8 karakter.';
        }
        if (mb_strlen($displayName) < 2) {
            $errors['display_name'] = 'Nama tampilan minimal 2 karakter.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (email, password_hash, display_name) VALUES (:e, :p, :n)
                 RETURNING id, email, display_name, role::text AS role, email_verified_at',
            );
            $stmt->execute([':e' => $email, ':p' => password_hash($password, PASSWORD_DEFAULT), ':n' => $displayName]);
            $user = $this->publicUser($stmt->fetch(\PDO::FETCH_ASSOC));
            $token = $this->createVerificationToken($user['id']);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23505') {
                throw ApiException::duplicate('Email');
            }
            throw $e;
        }

        return ['user' => $user, 'verification_token' => $token];
    }

    public function verifyEmail(string $token): void
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare(
            'UPDATE email_verifications SET used_at = now()
              WHERE token_hash = :h AND used_at IS NULL AND expires_at > now()
          RETURNING user_id',
        );
        $stmt->execute([':h' => $this->hash($token)]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            $this->pdo->rollBack();
            throw ApiException::validation(['token' => 'Token verifikasi tidak valid atau kedaluwarsa.']);
        }
        $this->pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, now()) WHERE id = :id')
            ->execute([':id' => $userId]);
        $this->pdo->commit();
    }

    /** @return string|null token baru untuk dikirim lewat email, atau null (tidak membocorkan keberadaan akun) */
    public function resendVerification(string $email): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM users WHERE email = :e AND email_verified_at IS NULL AND deleted_at IS NULL AND disabled_at IS NULL',
        );
        $stmt->execute([':e' => $this->normalizeEmail($email)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->createVerificationToken((string) $id);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function login(string $email, string $password): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, password_hash, disabled_at, deleted_at FROM users WHERE email = :e',
        );
        $stmt->execute([':e' => $this->normalizeEmail($email)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Selalu jalankan password_verify agar waktu respons tidak membedakan "email tidak ada".
        $valid = password_verify($password, $row['password_hash'] ?? self::DUMMY_HASH);
        if ($row === false || !$valid || $row['disabled_at'] !== null || $row['deleted_at'] !== null) {
            throw ApiException::unauthorized('Email atau password salah.');
        }

        return $this->issueTokens($row['id']);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function refresh(string $refreshToken): array
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare(
            "SELECT t.user_id, t.pair_id FROM api_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = :h AND t.kind = 'refresh' AND t.revoked_at IS NULL AND t.expires_at > now()
                AND u.deleted_at IS NULL AND u.disabled_at IS NULL
              FOR UPDATE OF t",
        );
        $stmt->execute([':h' => $this->hash($refreshToken)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            $this->pdo->rollBack();
            throw ApiException::unauthorized('Refresh token tidak valid atau kedaluwarsa.');
        }
        $this->revokePair($row['pair_id']);
        $tokens = $this->issueTokens($row['user_id']);
        $this->pdo->commit();

        return $tokens;
    }

    /** Mencabut pasangan token milik token yang dipresentasikan. Idempoten. */
    public function logout(string $token): void
    {
        $stmt = $this->pdo->prepare('SELECT pair_id FROM api_tokens WHERE token_hash = :h');
        $stmt->execute([':h' => $this->hash($token)]);
        $pair = $stmt->fetchColumn();
        if ($pair !== false) {
            $this->revokePair((string) $pair);
        }
    }

    /** @return array<string, mixed>|null pengguna aktif pemilik access token, atau null */
    public function authenticate(string $accessToken): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.email, u.display_name, u.role::text AS role, u.email_verified_at
               FROM api_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = :h AND t.kind = 'access' AND t.revoked_at IS NULL AND t.expires_at > now()
                AND u.deleted_at IS NULL AND u.disabled_at IS NULL",
        );
        $stmt->execute([':h' => $this->hash($accessToken)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->publicUser($row);
    }

    /** @return array<string, mixed> */
    public function updateProfile(string $userId, string $displayName): array
    {
        $displayName = trim($displayName);
        if (mb_strlen($displayName) < 2) {
            throw ApiException::validation(['display_name' => 'Nama tampilan minimal 2 karakter.']);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE users SET display_name = :n WHERE id = :id AND deleted_at IS NULL
         RETURNING id, email, display_name, role::text AS role, email_verified_at',
        );
        $stmt->execute([':n' => $displayName, ':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw ApiException::notFound('Akun');
        }

        return $this->publicUser($row);
    }

    /** Mengubah password dan mencabut semua sesi lain (sesi saat ini, bila diberikan, dipertahankan). */
    public function changePassword(string $userId, string $current, string $new, ?string $keepAccessToken = null): void
    {
        if (strlen($new) < 8) {
            throw ApiException::validation(['new_password' => 'Password minimal 8 karakter.']);
        }
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();
        if ($hash === false || !password_verify($current, (string) $hash)) {
            throw ApiException::validation(['current_password' => 'Password saat ini salah.']);
        }

        $keepPair = null;
        if ($keepAccessToken !== null) {
            $k = $this->pdo->prepare('SELECT pair_id FROM api_tokens WHERE token_hash = :h AND user_id = :u');
            $k->execute([':h' => $this->hash($keepAccessToken), ':u' => $userId]);
            $keepPair = $k->fetchColumn() ?: null;
        }

        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
            ->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $userId]);
        $this->pdo->prepare(
            'UPDATE api_tokens SET revoked_at = now()
              WHERE user_id = :u AND revoked_at IS NULL AND (:keep::uuid IS NULL OR pair_id <> :keep::uuid)',
        )->execute([':u' => $userId, ':keep' => $keepPair]);
        $this->pdo->commit();
    }

    /**
     * Penghapusan akun (syarat umum Google Play, PRD 9.2): anonimisasi, BUKAN menghapus riwayat.
     * Laporan dan status_history tetap utuh agar integritas jejak audit terjaga.
     */
    public function deleteAccount(string $userId): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->prepare(
            "UPDATE users
                SET email = 'deleted-' || id::text || '@invalid.local',
                    display_name = 'Akun dihapus',
                    password_hash = '!',
                    deleted_at = now()
              WHERE id = :id AND deleted_at IS NULL",
        )->execute([':id' => $userId]);
        $this->pdo->prepare('UPDATE api_tokens SET revoked_at = now() WHERE user_id = :id AND revoked_at IS NULL')
            ->execute([':id' => $userId]);
        $this->pdo->prepare('DELETE FROM email_verifications WHERE user_id = :id')->execute([':id' => $userId]);
        $this->pdo->commit();
    }

    // ---------- internal ----------

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    private function issueTokens(string $userId): array
    {
        $pair = (string) $this->pdo->query('SELECT gen_random_uuid()')->fetchColumn();
        $access = $this->newToken();
        $refresh = $this->newToken();
        $ins = $this->pdo->prepare(
            "INSERT INTO api_tokens (user_id, pair_id, kind, token_hash, expires_at)
             VALUES (:u, :p, :k, :h, now() + make_interval(secs => :ttl))",
        );
        $ins->execute([':u' => $userId, ':p' => $pair, ':k' => 'access', ':h' => $this->hash($access), ':ttl' => $this->accessTtlSeconds]);
        $ins->execute([':u' => $userId, ':p' => $pair, ':k' => 'refresh', ':h' => $this->hash($refresh), ':ttl' => $this->refreshTtlSeconds]);

        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $this->accessTtlSeconds];
    }

    private function revokePair(string $pairId): void
    {
        $this->pdo->prepare('UPDATE api_tokens SET revoked_at = now() WHERE pair_id = :p AND revoked_at IS NULL')
            ->execute([':p' => $pairId]);
    }

    private function createVerificationToken(string $userId): string
    {
        $token = $this->newToken();
        $this->pdo->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:u, :h, now() + make_interval(secs => :ttl))',
        )->execute([':u' => $userId, ':h' => $this->hash($token), ':ttl' => $this->verifyTtlSeconds]);

        return $token;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicUser(array $row): array
    {
        return [
            'id' => $row['id'],
            'email' => $row['email'],
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'email_verified' => $row['email_verified_at'] !== null,
        ];
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
