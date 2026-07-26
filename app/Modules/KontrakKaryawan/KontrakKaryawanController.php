<?php

declare(strict_types=1);

namespace App\Modules\KontrakKaryawan;

use App\Helpers\ApiResponse;
use App\Modules\KontrakKaryawan\Requests\StoreKontrakKaryawanRequest;
use App\Modules\KontrakKaryawan\Requests\UpdateKontrakKaryawanRequest;
use App\Modules\KontrakKaryawan\Resources\KontrakKaryawanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KontrakKaryawanController extends Controller
{
    public function __construct(private readonly KontrakKaryawanService $service) {}

    public function index(Request $request, string $idKaryawan): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $rows = $this->service->listByKaryawan($idKaryawan, $idPerusahaan);

        return ApiResponse::success(KontrakKaryawanResource::collection($rows));
    }

    public function store(StoreKontrakKaryawanRequest $request, string $idKaryawan): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($idKaryawan, $idPerusahaan, $request->validated());

        return ApiResponse::success(new KontrakKaryawanResource($record), 'Kontrak karyawan berhasil dibuat', 201);
    }

    public function update(UpdateKontrakKaryawanRequest $request, string $idKaryawan, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $idPerusahaan, $request->validated());

        return ApiResponse::success(new KontrakKaryawanResource($record), 'Kontrak karyawan berhasil diperbarui');
    }

    public function destroy(Request $request, string $idKaryawan, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);

        return ApiResponse::success(null, 'Kontrak karyawan berhasil dihapus');
    }
}
