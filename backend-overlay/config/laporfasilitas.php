<?php

// BELUM DIJALANKAN: ditulis tanpa akses ke Composer/Laravel. Uji di mesin Anda (lihat docs/SETUP-LARAVEL.md).
return [
    // Keputusan #1: publikasi otomatis MATI sampai model AI terbukti layak.
    'auto_publish' => (bool) env('LAPOR_AUTO_PUBLISH', false),

    // Keputusan #18 (masa berlaku token) masih terbuka; angka ini hanya usulan.
    'access_ttl' => (int) env('LAPOR_ACCESS_TTL', 3600),
    'refresh_ttl' => (int) env('LAPOR_REFRESH_TTL', 2592000),
    'verify_ttl' => (int) env('LAPOR_VERIFY_TTL', 86400),

    // PLACEHOLDER (Keputusan #23 belum ditetapkan): batas foto per laporan dan ukuran berkas (KB).
    'max_images' => (int) env('LAPOR_MAX_IMAGES', 5),
    'max_image_kb' => (int) env('LAPOR_MAX_IMAGE_KB', 5120),

    // AI: SELAMA ambang null, tidak ada foto yang bisa Passed (semua ke Perlu Review). Isi HANYA dari hasil evaluasi model (#8).
    'ai' => [
        'pass_min_confidence' => env('LAPOR_AI_PASS_MIN') !== null ? (float) env('LAPOR_AI_PASS_MIN') : null,
        'threshold_version' => env('LAPOR_AI_THRESHOLD_VERSION', 'belum-ditetapkan'),
    ],

    // Batas area pilot Samarinda (Keputusan #10). null = pemeriksaan area dimatikan. Format: [minLat, minLon, maxLat, maxLon].
    'pilot_bounds' => null,

    // Selama SMTP belum disiapkan, email verifikasi tidak sampai ke penguji. Matikan syarat ini untuk uji tertutup.
    'require_email_verification' => (bool) env('LAPOR_REQUIRE_EMAIL_VERIFICATION', false),
];
