<?php

declare(strict_types=1);

namespace App\Application;

/**
 * AI pengganti SEBELUM model nyata siap. Sengaja tidak pernah meyakinkan: confidence 0, kategori tidak cocok.
 * Dengan VerificationPolicy bawaan, semua laporan berakhir di Perlu Review (diperiksa manusia).
 * Jangan menampilkan angka apa pun dari stub ini sebagai akurasi model.
 */
final class StubAiClient implements AiClient
{
    public function analyze(string $storageKey, string $selectedCategory): AiResult
    {
        return new AiResult('tidak_diketahui', 0.0, false, 'unknown', 'stub-0');
    }
}
