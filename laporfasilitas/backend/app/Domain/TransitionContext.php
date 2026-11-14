<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Fakta yang dikumpulkan backend SEBELUM meminta transisi.
 * Klien tidak pernah mengisi ini; semuanya dihitung dari database.
 */
final class TransitionContext
{
    public function __construct(
        public readonly Role $role,
        public readonly bool $isOwner = false,
        public readonly bool $isClaimHolder = false,
        public readonly ?string $reason = null,
        public readonly bool $autoPublishEnabled = false,
        public readonly bool $hasActiveClaim = false,
        public readonly bool $evidenceSubmitted = false,
        public readonly bool $evidenceApproved = false,
        public readonly bool $reopenApproved = false,
    ) {
    }
}
