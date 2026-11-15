<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Role;

/** Siapa yang meminta aksi. Untuk proses backend: new Actor(null, Role::System). */
final class Actor
{
    public function __construct(
        public readonly ?string $userId,
        public readonly Role $role,
    ) {
    }

    public static function system(): self
    {
        return new self(null, Role::System);
    }
}
