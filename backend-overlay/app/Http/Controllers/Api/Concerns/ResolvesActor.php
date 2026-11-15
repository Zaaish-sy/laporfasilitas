<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Application\Actor;
use App\Domain\Role;
use Illuminate\Http\Request;

/** BELUM DIJALANKAN. Mengubah pengguna hasil AuthenticateToken menjadi Actor untuk layanan inti. */
trait ResolvesActor
{
    private function actor(Request $request): Actor
    {
        $u = $request->attributes->get('auth_user');

        return new Actor($u['id'], Role::from($u['role']));
    }
}
