<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Helpers\ApiResponse;
use App\Modules\LaporanPerjalanan\Requests\StoreFotoLaporanRequest;
use App\Modules\LaporanPerjalanan\Requests\StoreLaporanPerjalananRequest;
use App\Modules\LaporanPerjalanan\Resources\FotoLaporanResource;
use App\Modules\LaporanPerjalanan\Resources\LaporanPerjalananResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LaporanPerjalananController extends Controller
{
    public function __construct(private readonly LaporanPerjalananService $service) {}

    public function showByTrip(Request $request, string $idTrip): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->showByTrip($idTrip, $idPerusahaan);
        return ApiResponse::success(new LaporanPerjalananResource($record));
    }

    public function store(StoreLaporanPerjalananRequest $request, string $idTrip): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->createForTrip($idTrip, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new LaporanPerjalananResource($record), 'Laporan perjalanan berhasil dibuat', 201);
    }

    public function update(StoreLaporanPerjalananRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new LaporanPerjalananResource($record), 'Laporan perjalanan berhasil diperbarui');
    }

    public function storeFoto(StoreFotoLaporanRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->addFoto($id, $request->validated(), $request->file('file'), $idPerusahaan);
        return ApiResponse::success(new FotoLaporanResource($record), 'Foto laporan berhasil diunggah', 201);
    }

    public function destroyFoto(Request $request, string $id, string $idFoto): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->deleteFoto($id, $idFoto, $idPerusahaan);
        return ApiResponse::success(null, 'Foto laporan berhasil dihapus');
    }
}
