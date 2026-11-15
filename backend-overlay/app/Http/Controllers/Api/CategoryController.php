<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\ReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** BELUM DIJALANKAN. */
final class CategoryController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->reports->listCategories());
    }
}
