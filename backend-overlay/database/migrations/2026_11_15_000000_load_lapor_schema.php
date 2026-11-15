<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// BELUM DIJALANKAN. Sumber kebenaran skema adalah db/schema.sql di akar repo (bukan migrasi Laravel),
// supaya OpenAPI, SQL, dan PHP tetap sinkron dan bisa diperiksa tools/check_parity.py.
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared((string) file_get_contents(base_path('../db/schema.sql')));
    }

    public function down(): void
    {
        // Sengaja kosong: jalankan ulang dengan database baru. Jangan menjatuhkan data tanpa sengaja.
    }
};
