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
use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LaporanPerjalananController extends Controller
{
    public function __construct(
        private readonly LaporanPerjalananService $service,
        private readonly SupirRepositoryInterface $supirRepo,
        private readonly SupirVendorRepositoryInterface $supirVendorRepo,
    ) {}

    private function konteksSupirSaya(Request $request): array
    {
        $idPengguna = (string) $request->user()->id_pengguna;

        $supir = $this->supirRepo->findByPengguna($idPengguna);
        if ($supir !== null) {
            return ['internal', (string) $supir->id_supir];
        }

        $supirVendor = $this->supirVendorRepo->findByPengguna($idPengguna);
        if ($supirVendor !== null) {
            return ['vendor', (string) $supirVendor->id_supir_vendor];
        }

        abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
    }

    public function laporanSaya(Request $request, string $idTrip): JsonResponse
    {
        [$tipe, $idSupir] = $this->konteksSupirSaya($request);
        $laporan = $this->service->showUntukSupir($idTrip, $idSupir, $tipe);
        return ApiResponse::success($laporan === null ? null : new LaporanPerjalananResource($laporan));
    }

    public function storeLaporanSaya(StoreLaporanPerjalananRequest $request, string $idTrip): JsonResponse
    {
        [$tipe, $idSupir] = $this->konteksSupirSaya($request);
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->upsertUntukSupir($idTrip, $idSupir, $request->validated(), $idPerusahaan, $request->file('foto', []), $tipe);
        return ApiResponse::success(new LaporanPerjalananResource($record), 'Laporan perjalanan tersimpan', 201);
    }

    public function storeFotoSaya(StoreFotoLaporanRequest $request, string $idLaporan): JsonResponse
    {
        [$tipe, $idSupir] = $this->konteksSupirSaya($request);
        $records = $this->service->addFotoUntukSupir($idLaporan, $idSupir, $request->file('foto'), $request->validated('keterangan'), $tipe, $request->validated('foto_keterangan'));
        return ApiResponse::success(FotoLaporanResource::collection($records), 'Foto laporan berhasil diunggah', 201);
    }

    public function destroyFotoSaya(Request $request, string $idLaporan, string $idFoto): JsonResponse
    {
        [$tipe, $idSupir] = $this->konteksSupirSaya($request);
        $this->service->deleteFotoUntukSupir($idLaporan, $idFoto, $idSupir, $tipe);
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
        $records = $this->service->addFoto($id, $request->file('foto'), $idPerusahaan, $request->validated('keterangan'), $request->validated('foto_keterangan'));
        return ApiResponse::success(FotoLaporanResource::collection($records), 'Foto laporan berhasil diunggah', 201);
    }

    public function destroyFoto(Request $request, string $id, string $idFoto): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->deleteFoto($id, $idFoto, $idPerusahaan);
        return ApiResponse::success(null, 'Foto laporan berhasil dihapus');
    }
}
