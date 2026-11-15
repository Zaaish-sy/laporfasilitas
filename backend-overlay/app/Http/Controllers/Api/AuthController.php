<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\AuthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * BELUM DIJALANKAN. Controller TIPIS: semua aturan ada di AuthService (sudah diuji ke PostgreSQL).
 * Kontrak: docs/openapi.yaml tag "Auth". Validasi dilakukan di AuthService, bukan di sini.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $result = $this->auth->register(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            (string) $request->input('display_name', ''),
        );
        $this->sendVerification($result['user']['email'], $result['verification_token']);

        return response()->json($result['user'], 201);   // token verifikasi TIDAK dikembalikan ke klien
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $this->auth->verifyEmail((string) $request->input('token', ''));

        return response()->json(null, 204);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $email = (string) $request->input('email', '');
        $token = $this->auth->resendVerification($email);
        if ($token !== null) {
            $this->sendVerification($email, $token);
        }

        return response()->json(null, 202);               // selalu 202: tidak membocorkan keberadaan akun
    }

    public function login(Request $request): JsonResponse
    {
        return response()->json($this->auth->login(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
        ));
    }

    public function refresh(Request $request): JsonResponse
    {
        return response()->json($this->auth->refresh((string) $request->input('refresh_token', '')));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout((string) $request->bearerToken());

        return response()->json(null, 204);
    }

    /** TODO (Keputusan terbuka): penyedia SMTP. Dengan MAIL_MAILER=log, email hanya masuk storage/logs. */
    private function sendVerification(string $email, string $token): void
    {
        Mail::raw(
            "Kode verifikasi LaporFasilitas Anda: {$token}\nBerlaku 24 jam. Abaikan jika Anda tidak mendaftar.",
            static fn ($m) => $m->to($email)->subject('Verifikasi email LaporFasilitas'),
        );
    }
}
