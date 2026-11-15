<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\AiClient;
use App\Application\AnalysisProcessor;
use App\Application\AuthService;
use App\Application\ReportService;
use App\Application\ReportTransitions;
use App\Application\StubAiClient;
use App\Application\VerificationPolicy;
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

        // Flag publikasi otomatis HANYA hidup di sini (satu sumber kebenaran).
        $this->app->bind(ReportTransitions::class, fn () => new ReportTransitions(
            DB::connection()->getPdo(),
            (bool) config('laporfasilitas.auto_publish'),
        ));

        $this->app->bind(ReportService::class, fn ($app) => new ReportService(
            DB::connection()->getPdo(),
            $app->make(ReportTransitions::class),
            (bool) config('laporfasilitas.require_email_verification'),
            (int) config('laporfasilitas.max_images'),
            '/api/v1/images/%s/file',
        ));

        // Sampai model nyata siap: stub yang selalu "tidak meyakinkan" -> semua laporan ke Perlu Review.
        $this->app->bind(AiClient::class, StubAiClient::class);
        $this->app->bind(VerificationPolicy::class, fn () => new VerificationPolicy(
            (string) config('laporfasilitas.ai.threshold_version'),
            config('laporfasilitas.ai.pass_min_confidence'),
        ));

        $this->app->bind(AnalysisProcessor::class, function ($app) {
            $b = config('laporfasilitas.pilot_bounds');

            return new AnalysisProcessor(
                DB::connection()->getPdo(),
                $app->make(ReportTransitions::class),
                $app->make(AiClient::class),
                $app->make(VerificationPolicy::class),
                $b === null ? null : ['minLat' => $b[0], 'minLon' => $b[1], 'maxLat' => $b[2], 'maxLon' => $b[3]],
            );
        });
    }
}
