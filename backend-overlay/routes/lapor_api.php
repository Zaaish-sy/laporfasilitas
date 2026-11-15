<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\MeController;
use App\Http\Middleware\AuthenticateToken;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

// BELUM DIJALANKAN. Awalan /api/v1 diatur di bootstrap/app.php (apiPrefix). Angka throttle hanya placeholder (#23).
Route::middleware('throttle:10,1')->prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('resend-verification', [AuthController::class, 'resendVerification']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);
});

Route::middleware(AuthenticateToken::class)->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);
    Route::delete('me', [MeController::class, 'destroy']);
    Route::post('me/password', [MeController::class, 'changePassword']);

    Route::get('categories', [CategoryController::class, 'index']);

    // Urutan penting: 'reports/mine' HARUS di atas 'reports/{id}'.
    Route::get('reports/mine', [ReportController::class, 'mine']);
    Route::get('reports', [ReportController::class, 'index']);
    Route::post('reports', [ReportController::class, 'store']);
    Route::get('reports/{id}', [ReportController::class, 'show']);
    Route::patch('reports/{id}', [ReportController::class, 'update']);
    Route::post('reports/{id}/images', [ReportController::class, 'uploadImage']);
    Route::post('reports/{id}/submit', [ReportController::class, 'submit']);
    Route::get('reports/{id}/history', [ReportController::class, 'history']);
    Route::get('reports/{id}/analysis', [ReportController::class, 'analysis']);
    Route::get('images/{id}/file', [ImageController::class, 'file']);

    // Modul berikutnya (lihat README "urutan modul"): komunitas, klaim, moderasi, admin.
    // Contoh pemasangan peran: Route::middleware(EnsureRole::class . ':admin')->prefix('admin')->group(...)
});
