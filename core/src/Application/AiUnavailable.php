<?php

declare(strict_types=1);

namespace App\Application;

/** Layanan AI tidak tersedia / timeout. Pemanggil harus mengarahkan laporan ke Perlu Review (A-02), bukan meloloskannya. */
final class AiUnavailable extends \RuntimeException
{
}
