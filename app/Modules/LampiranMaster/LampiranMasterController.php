<?php

declare(strict_types=1);

namespace App\Modules\LampiranMaster;

use App\Helpers\ApiResponse;
use App\Http\Middleware\CheckIzinPeran;
use App\Modules\LampiranMaster\Requests\UploadLampiranMasterRequest;
use App\Modules\LampiranMaster\Resources\LampiranMasterResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LampiranMasterController extends Controller
{
    public function __construct(private readonly LampiranMasterService $service) {}

    public function indexVendor(Request $request, string $id): JsonResponse
    {
        return $this->daftar($request, LampiranMasterService::JENIS_VENDOR, $id);
    }

    public function storeVendor(UploadLampiranMasterRequest $request, string $id): JsonResponse
    {
        return $this->unggah($request, LampiranMasterService::JENIS_VENDOR, $id);
    }

    public function destroyVendor(Request $request, string $id, string $idLampiran): JsonResponse
    {
        return $this->hapus($request, LampiranMasterService::JENIS_VENDOR, $id, $idLampiran);
    }

    public function indexSupplier(Request $request, string $id): JsonResponse
    {
        return $this->daftar($request, LampiranMasterService::JENIS_SUPPLIER, $id);
    }

    public function storeSupplier(UploadLampiranMasterRequest $request, string $id): JsonResponse
    {
        return $this->unggah($request, LampiranMasterService::JENIS_SUPPLIER, $id);
    }

    public function destroySupplier(Request $request, string $id, string $idLampiran): JsonResponse
    {
        return $this->hapus($request, LampiranMasterService::JENIS_SUPPLIER, $id, $idLampiran);
    }

    private function daftar(Request $request, string $jenis, string $id): JsonResponse
    {
        $daftar = $this->service->daftar($jenis, $id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(LampiranMasterResource::collection($daftar));
    }

    private function unggah(UploadLampiranMasterRequest $request, string $jenis, string $id): JsonResponse
    {
        if (!$this->bolehKelola($request, $jenis)) {
            return $this->tolakIzin();
        }

        $daftar = $this->service->tambah($jenis, $id, $request->file('lampiran', []), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(LampiranMasterResource::collection($daftar), 'Lampiran berhasil diunggah', 201);
    }

    private function hapus(Request $request, string $jenis, string $id, string $idLampiran): JsonResponse
    {
        if (!$this->bolehKelola($request, $jenis)) {
            return $this->tolakIzin();
        }

        $this->service->hapus($jenis, $id, $idLampiran, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Lampiran berhasil dihapus');
    }

    private function bolehKelola(Request $request, string $jenis): bool
    {
        return CheckIzinPeran::diizinkan($request->user(), $jenis, 'ubah');
    }

    private function tolakIzin(): JsonResponse
    {
        return ApiResponse::error('Anda tidak memiliki izin untuk aksi ini', null, 403);
    }
}
