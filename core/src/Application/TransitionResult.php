<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\ReportStatus;

final class TransitionResult
{
    public function __construct(
        public readonly ReportStatus $from,
        public readonly ReportStatus $to,
        public readonly string $transitionId,
    ) {
    }
}
