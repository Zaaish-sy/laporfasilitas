<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\ApiException;
use App\Application\ReportService;
use App\Http\Controllers\Api\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** BELUM DIJALANKAN. Menyajikan foto hanya bila pemirsa boleh melihat laporannya (tanpa URL publik/bertanda tangan). */
final class ImageController extends Controller
{
    use ResolvesActor;

    public function __construct(private readonly ReportService $reports)
    {
    }

    public function file(Request $request, string $id)
    {
        $key = $this->reports->imageStorageKey($this->actor($request), $id);
        if (!Storage::disk('local')->exists($key)) {
            throw ApiException::notFound('Foto');
        }

        return Storage::disk('local')->response($key, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
