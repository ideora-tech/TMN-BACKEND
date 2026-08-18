<?php

declare(strict_types=1);

namespace App\Modules\PengaturanKode;

use App\Helpers\ApiResponse;
use App\Modules\PengaturanKode\Requests\UpdatePengaturanKodeRequest;
use App\Modules\PengaturanKode\Resources\PengaturanKodeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PengaturanKodeController extends Controller
{
    public function __construct(private readonly PengaturanKodeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $this->service->list($idPerusahaan);

        return ApiResponse::success(PengaturanKodeResource::collection($data));
    }

    public function update(UpdatePengaturanKodeRequest $request, string $entitas): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($idPerusahaan, $entitas, $request->validated());

        return ApiResponse::success(new PengaturanKodeResource($record), 'Pengaturan kode berhasil diperbarui');
    }
}
