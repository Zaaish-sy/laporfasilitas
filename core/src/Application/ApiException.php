<?php

declare(strict_types=1);

namespace App\Application;

/** Galat bisnis yang langsung dipetakan ke respons API { code, message, details }. */
final class ApiException extends \DomainException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $fields */
    public static function validation(array $fields): self
    {
        return new self('Validasi gagal.', 422, 'validation_failed', ['fields' => $fields]);
    }

    public static function duplicate(string $what): self
    {
        return new self("{$what} sudah terdaftar.", 409, 'duplicate');
    }

    public static function unauthorized(string $message = 'Belum login atau token tidak valid.'): self
    {
        return new self($message, 401, 'unauthorized');
    }

    public static function forbidden(string $message, string $code = 'forbidden'): self
    {
        return new self($message, 403, $code);
    }

    public static function conflict(string $message, string $code): self
    {
        return new self($message, 409, $code);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what} tidak ditemukan.", 404, 'not_found');
    }
}
