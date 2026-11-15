<?php

declare(strict_types=1);

// Autoloader sederhana (tanpa Composer) agar domain bisa diuji berdiri sendiri.
// Di proyek Laravel, namespace App\ sudah di-autoload oleh Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
