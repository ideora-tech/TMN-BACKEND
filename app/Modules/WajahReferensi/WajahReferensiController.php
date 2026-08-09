<?php

declare(strict_types=1);

namespace App\Modules\WajahReferensi;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\WajahReferensi\Requests\DaftarWajahRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WajahReferensiController extends Controller
{
    public function __construct(private readonly WajahReferensiService $service) {}

    public function saya(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->saya((string) $request->user()->id_pengguna));
    }

    public function daftar(DaftarWajahRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $hasil = $this->service->daftar(
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
            $request->file('foto'),
            $validated['embedding'],
            $validated['model_versi'],
        );

        return ApiResponse::success($hasil, 'Wajah berhasil didaftarkan', 201);
    }
}
