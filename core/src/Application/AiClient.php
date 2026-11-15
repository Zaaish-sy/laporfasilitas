<?php

declare(strict_types=1);

namespace App\Application;

interface AiClient
{
    /** @throws AiUnavailable bila layanan tidak tersedia atau melewati batas waktu */
    public function analyze(string $storageKey, string $selectedCategory): AiResult;
}
