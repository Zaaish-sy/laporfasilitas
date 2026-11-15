<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\AnalysisProcessor;
use App\Application\ApiException;
use App\Application\ReportService;
use App\Http\Controllers\Api\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * BELUM DIJALANKAN. Controller TIPIS; aturan ada di ReportService / AnalysisProcessor (sudah diuji ke PostgreSQL).
 * Kontrak: docs/openapi.yaml tag "Laporan". Hanya unggah berkas yang menyentuh sistem berkas di sini.
 */
final class ReportController extends Controller
{
    use ResolvesActor;

    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly ReportService $reports)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $r = $this->reports->createDraft($this->actor($request), $request->all());

        return response()->json($r['report'], $r['created'] ? 201 : 200);     // 200 = client_uuid sudah ada (idempoten)
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->reports->listPublic($this->actor($request), $request->query()));
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json($this->reports->listMine($this->actor($request), $request->query()));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->reports->get($this->actor($request), $id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json($this->reports->updateDraft($this->actor($request), $id, $request->all()));
    }

    public function uploadImage(Request $request, string $id): JsonResponse
    {
        $file = $request->file('file');
        if ($file === null || !$file->isValid()) {
            throw ApiException::validation(['file' => 'Berkas foto wajib dikirim.']);
        }
        $mime = (string) $file->getMimeType();                      // dideteksi dari ISI berkas, bukan nama/ekstensi
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw ApiException::validation(['file' => 'Hanya JPEG, PNG, atau WebP.']);
        }
        $maxKb = (int) config('laporfasilitas.max_image_kb');       // PLACEHOLDER Keputusan #23
        if ($file->getSize() > $maxKb * 1024) {
            throw ApiException::validation(['file' => "Ukuran foto melebihi {$maxKb} KB."]);
        }

        $sha = hash_file('sha256', $file->getRealPath());
        if (strtolower((string) $request->input('sha256', $sha)) !== $sha) {
            throw ApiException::validation(['sha256' => 'sha256 tidak cocok dengan isi berkas.']);
        }

        // Simpan DULU berdasarkan hash (idempoten: berkas sama menimpa dirinya sendiri), lalu catat di database.
        $key = "reports/{$id}/{$sha}." . self::ALLOWED_MIME[$mime];
        Storage::disk('local')->putFileAs("reports/{$id}", $file, "{$sha}." . self::ALLOWED_MIME[$mime]);

        // TODO (Keputusan #20): hapus metadata EXIF (termasuk lokasi) dari foto yang disajikan ke publik.
        $r = $this->reports->addImage($this->actor($request), $id, $key, $sha, $request->input('taken_at'));

        return response()->json($r['image'], $r['created'] ? 201 : 200);
    }

    public function submit(Request $request, string $id, AnalysisProcessor $processor): JsonResponse
    {
        $report = $this->reports->submit($this->actor($request), $id);

        // Analisis dijalankan SETELAH respons terkirim (A-14). Gagal di sini tidak boleh membatalkan pengiriman:
        // laporan tetap menunggu_analisis dan bisa diproses ulang (process() idempoten).
        dispatch(static function () use ($processor, $id): void {
            $processor->process($id);
        })->afterResponse();

        return response()->json($report, 202);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        return response()->json($this->reports->history($this->actor($request), $id));
    }

    public function analysis(Request $request, string $id): JsonResponse
    {
        return response()->json($this->reports->analysis($this->actor($request), $id));
    }
}
