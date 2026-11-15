<?php

declare(strict_types=1);

// Pembantu uji integrasi ke PostgreSQL ASLI. Setiap proses uji memakai SKEMA sementara sendiri
// (dibuat dari db/schema.sql lalu dihapus), sehingga aman dijalankan berulang dan tidak mengotori data.
//
// Env (opsional): LAPOR_TEST_DSN, LAPOR_TEST_USER, LAPOR_TEST_PASS
require __DIR__ . '/../bootstrap.php';

final class Db
{
    public static string $schema = '';
    private static int $pass = 0;
    private static int $fail = 0;

    public static function connect(): \PDO
    {
        $dsn = getenv('LAPOR_TEST_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=lapor_dev';
        $pdo = new \PDO($dsn, getenv('LAPOR_TEST_USER') ?: 'lapor', getenv('LAPOR_TEST_PASS') ?: 'lapor', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        if (self::$schema !== '') {
            $pdo->exec('SET search_path TO ' . self::$schema . ', public');
        }

        return $pdo;
    }

    /** Buat skema sementara berisi seluruh db/schema.sql. Mengembalikan koneksi utama. */
    public static function boot(): \PDO
    {
        self::$schema = 't_' . bin2hex(random_bytes(4));
        $admin = self::connect();
        $admin->exec('CREATE SCHEMA ' . self::$schema);
        $pdo = self::connect();
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../db/schema.sql'));
        register_shutdown_function(static function (): void {
            try {
                self::connect()->exec('DROP SCHEMA IF EXISTS ' . self::$schema . ' CASCADE');
            } catch (\Throwable) {
            }
        });

        return $pdo;
    }

    public static function check(bool $ok, string $name): void
    {
        if ($ok) {
            self::$pass++;
        } else {
            self::$fail++;
            fwrite(STDERR, "GAGAL: {$name}\n");
        }
    }

    /** Mengharapkan galat aplikasi/domain dengan status HTTP dan kode tertentu. */
    public static function expect(callable $fn, int $status, string $code, string $name): void
    {
        try {
            $fn();
            self::check(false, "{$name} (tidak melempar galat)");
        } catch (\App\Application\ApiException | \App\Domain\TransitionException $e) {
            self::check($e->httpStatus === $status && $e->errorCode === $code,
                "{$name} (dapat {$e->httpStatus}/{$e->errorCode}: {$e->getMessage()})");
        }
    }

    public static function scalar(\PDO $pdo, string $sql, array $p = []): mixed
    {
        $s = $pdo->prepare($sql);
        $s->execute($p);

        return $s->fetchColumn();
    }

    public static function done(): never
    {
        echo "\nLulus: " . self::$pass . "  Gagal: " . self::$fail . "\n";
        exit(self::$fail === 0 ? 0 : 1);
    }
}
