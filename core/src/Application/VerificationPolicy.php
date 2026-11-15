<?php

declare(strict_types=1);

namespace App\Application;

/**
 * Mengubah respons AI menjadi verdict. Dihitung BACKEND, bukan AI (F4, F11).
 *
 * Ambang lolos ($passMinConfidence) sengaja null secara bawaan: angkanya hanya boleh ditetapkan dari hasil evaluasi
 * model pada dataset (Keputusan #8). Selama null, tidak ada foto yang bisa Passed; semuanya Needs Review.
 */
final class VerificationPolicy
{
    public function __construct(
        public readonly string $thresholdVersion = 'belum-ditetapkan',
        public readonly ?float $passMinConfidence = null,
    ) {
    }

    public function decide(AiResult $r): Verification
    {
        if ($r->imageQuality === 'poor') {
            return Verification::Rejected;          // foto tidak layak dianalisis (A-03)
        }
        if ($this->passMinConfidence !== null
            && $r->categoryMatch
            && $r->imageQuality === 'ok'
            && $r->confidence >= $this->passMinConfidence) {
            return Verification::Passed;
        }

        return Verification::NeedsReview;
    }
}
