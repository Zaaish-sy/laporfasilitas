<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\AuthService;
use App\Application\ReportTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/** BELUM DIJALANKAN. Mengikat layanan inti (core/) ke koneksi PostgreSQL Laravel. */
final class LaporFasilitasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthService::class, fn () => new AuthService(
            DB::connection()->getPdo(),
            (int) config('laporfasilitas.access_ttl'),
            (int) config('laporfasilitas.refresh_ttl'),
            (int) config('laporfasilitas.verify_ttl'),
        ));

        // Bukan singleton: setiap permintaan memakai PDO koneksi saat itu.
        $this->app->bind(ReportTransitions::class, fn () => new ReportTransitions(
            DB::connection()->getPdo(),
            (bool) config('laporfasilitas.auto_publish'),
        ));
    }
}
