<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\ApiException;
use App\Application\AuthService;
use Closure;
use Illuminate\Http\Request;

/** BELUM DIJALANKAN. Memeriksa bearer token; menaruh pengguna di $request->attributes['auth_user']. */
final class AuthenticateToken
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $user = $token ? $this->auth->authenticate($token) : null;
        if ($user === null) {
            throw ApiException::unauthorized();
        }
        $request->attributes->set('auth_user', $user);

        return $next($request);
    }
}
