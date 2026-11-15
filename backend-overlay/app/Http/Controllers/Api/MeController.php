<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\AuthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** BELUM DIJALANKAN. Kontrak: docs/openapi.yaml, path /me*. */
final class MeController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($request->attributes->get('auth_user'));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        return response()->json($this->auth->updateProfile($user['id'], (string) $request->input('display_name', '')));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $this->auth->changePassword(
            $user['id'],
            (string) $request->input('current_password', ''),
            (string) $request->input('new_password', ''),
            $request->bearerToken(),
        );

        return response()->json(null, 204);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->auth->deleteAccount($request->attributes->get('auth_user')['id']);

        return response()->json(null, 204);
    }
}
