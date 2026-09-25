<?php

declare(strict_types=1);

namespace App\Modules\Pengadaan;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PengadaanController extends Controller
{
    public function __construct(private readonly PengadaanService $service) {}

    public function ringkasan(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->ringkasan((string) $request->user()->id_perusahaan));
    }
}
