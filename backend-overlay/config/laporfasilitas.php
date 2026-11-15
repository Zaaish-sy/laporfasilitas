<?php

// BELUM DIJALANKAN: ditulis tanpa akses ke Composer/Laravel. Uji di mesin Anda (lihat docs/SETUP-LARAVEL.md).
return [
    // Keputusan #1: publikasi otomatis MATI sampai model AI terbukti layak.
    'auto_publish' => (bool) env('LAPOR_AUTO_PUBLISH', false),

    // Keputusan #18 (masa berlaku token) masih terbuka; angka ini hanya usulan.
    'access_ttl' => (int) env('LAPOR_ACCESS_TTL', 3600),
    'refresh_ttl' => (int) env('LAPOR_REFRESH_TTL', 2592000),
    'verify_ttl' => (int) env('LAPOR_VERIFY_TTL', 86400),

    // Selama SMTP belum disiapkan, email verifikasi tidak sampai ke penguji. Matikan syarat ini untuk uji tertutup.
    'require_email_verification' => (bool) env('LAPOR_REQUIRE_EMAIL_VERIFICATION', false),
];
