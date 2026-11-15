<?php

declare(strict_types=1);

namespace App\Domain;

/** Galat transisi, sudah membawa status HTTP dan kode galat API. */
final class TransitionException extends \DomainException
{
    private function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public static function invalid(ReportStatus $from, ReportStatus $to): self
    {
        return new self("Transisi {$from->value} -> {$to->value} tidak diizinkan.", 409, 'invalid_transition');
    }

    public static function forbidden(string $transitionId): self
    {
        return new self("Peran tidak berhak melakukan transisi {$transitionId}.", 403, 'forbidden');
    }

    public static function reasonRequired(string $transitionId): self
    {
        return new self("Transisi {$transitionId} wajib menyertakan alasan.", 422, 'reason_required');
    }

    public static function guard(string $transitionId, string $why): self
    {
        return new self("Transisi {$transitionId} ditolak: {$why}", 409, 'guard_failed');
    }
}
