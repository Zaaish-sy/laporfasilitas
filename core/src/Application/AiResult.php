<?php

declare(strict_types=1);

namespace App\Application;

/** Respons layanan AI (kontrak AIServiceResponse di docs/openapi.yaml). AI tidak menghitung verdict; itu tugas VerificationPolicy. */
final class AiResult
{
    public function __construct(
        public readonly string $predictedClass,
        public readonly float $confidence,
        public readonly bool $categoryMatch,
        public readonly string $imageQuality,   // 'ok' | 'poor' | 'unknown'
        public readonly string $modelVersion,
    ) {
    }
}
