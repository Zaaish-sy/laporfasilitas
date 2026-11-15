<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\TransitionException;
use Closure;
use Illuminate\Http\Request;

/** BELUM DIJALANKAN. Pemakaian rute: ->middleware('role:admin') atau 'role:volunteer,admin'. */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->attributes->get('auth_user');
        if ($user === null || !in_array($user['role'], $roles, true)) {
            throw TransitionException::forbidden('peran');
        }

        return $next($request);
    }
}
