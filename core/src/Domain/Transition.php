<?php

declare(strict_types=1);

namespace App\Domain;

/** Satu aturan transisi. Daftar lengkap ada di ReportStateMachine::rules(). */
final class Transition
{
    /**
     * @param list<ReportStatus> $from
     * @param list<Who> $who  cukup salah satu terpenuhi
     * @param (\Closure(TransitionContext): ?string)|null $guard  mengembalikan pesan galat, atau null jika lolos
     */
    public function __construct(
        public readonly string $id,
        public readonly array $from,
        public readonly ReportStatus $to,
        public readonly array $who,
        public readonly bool $requiresReason = false,
        public readonly ?\Closure $guard = null,
        public readonly string $note = '',
    ) {
    }
}
