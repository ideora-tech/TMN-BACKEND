<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Helpers\ApiResponse;
use App\Modules\LaporanPerjalanan\Requests\StoreFotoLaporanRequest;
use App\Modules\LaporanPerjalanan\Requests\StoreLaporanPerjalananRequest;
use App\Modules\LaporanPerjalanan\Requests\UpdateLaporanPerjalananRequest;
use App\Modules\LaporanPerjalanan\Resources\FotoLaporanResource;
use App\Modules\LaporanPerjalanan\Resources\LaporanPerjalananResource;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LaporanPerjalananController extends Controller
{
    public function __construct(
        private readonly LaporanPerjalananService $service,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

    private function idSupirSaya(Request $request): string
    {
        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }
        return (string) $supir->id_supir;
    }

    public function laporanSaya(Request $request, string $idTrip): JsonResponse
    {
        $idSupir = $this->idSupirSaya($request);
        $laporan = $this->service->showUntukSupir($idTrip, $idSupir);
        return ApiResponse::success($laporan === null ? null : new LaporanPerjalananResource($laporan));
    }

    public function storeLaporanSaya(StoreLaporanPerjalananRequest $request, string $idTrip): JsonResponse
    {
        $idSupir = $this->idSupirSaya($request);
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->upsertUntukSupir($idTrip, $idSupir, $request->validated(), $idPerusahaan, $request->file('foto', []));
        return ApiResponse::success(new LaporanPerjalananResource($record), 'Laporan perjalanan tersimpan', 201);
    }

    public function storeFotoSaya(StoreFotoLaporanRequest $request, string $idLaporan): JsonResponse
    {
        $idSupir = $this->idSupirSaya($request);
        $records = $this->service->addFotoUntukSupir($idLaporan, $idSupir, $request->file('foto'), $request->validated('keterangan'));
        return ApiResponse::success(FotoLaporanResource::collection($records), 'Foto laporan berhasil diunggah', 201);
    }

    public function destroyFotoSaya(Request $request, string $idLaporan, string $idFoto): JsonResponse
    {
        $idSupir = $this->idSupirSaya($request);
        $this->service->deleteFotoUntukSupir($idLaporan, $idFoto, $idSupir);
        return ApiResponse::success(null, 'Foto laporan berhasil dihapus');
    }

    public function showByTrip(Request $request, string $idTrip): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->showByTrip($idTrip, $idPerusahaan);
        return ApiResponse::success(new LaporanPerjalananResource($record));
    }

    public function store(StoreLaporanPerjalananRequest $request, string $idTrip): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->createForTrip($idTrip, $request->validated(), $idPerusahaan, $request->file('foto', []));
        return ApiResponse::success(new LaporanPerjalananResource($record), 'Laporan perjalanan berhasil dibuat', 201);
    }

    public function update(UpdateLaporanPerjalananRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan, $request->file('foto', []));
        return ApiResponse::success(new LaporanPerjalananResource($record), 'Laporan perjalanan berhasil diperbarui');
    }

    public function storeFoto(StoreFotoLaporanRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $records = $this->service->addFoto($id, $request->file('foto'), $idPerusahaan, $request->validated('keterangan'));
        return ApiResponse::success(FotoLaporanResource::collection($records), 'Foto laporan berhasil diunggah', 201);
    }

    public function destroyFoto(Request $request, string $id, string $idFoto): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->deleteFoto($id, $idFoto, $idPerusahaan);
        return ApiResponse::success(null, 'Foto laporan berhasil dihapus');
    }
}
