<?php

declare(strict_types=1);

namespace App\Application;

/** Hasil pemeriksaan awal AI. Nilai = enum ai_result di db/schema.sql. */
enum Verification: string
{
    case Passed = 'passed';
    case NeedsReview = 'needs_review';
    case Rejected = 'rejected';
}
