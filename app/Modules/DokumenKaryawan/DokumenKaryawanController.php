<?php

declare(strict_types=1);

namespace App\Modules\DokumenKaryawan;

use App\Helpers\ApiResponse;
use App\Modules\DokumenKaryawan\Requests\StoreDokumenKaryawanRequest;
use App\Modules\DokumenKaryawan\Requests\UpdateDokumenKaryawanRequest;
use App\Modules\DokumenKaryawan\Resources\DokumenKaryawanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DokumenKaryawanController extends Controller
{
    public function __construct(private readonly DokumenKaryawanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listByPerusahaan(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_karyawan'),
            $request->get('jenis_dokumen'),
            $request->get('search'),
        );

        return ApiResponse::paginated(
            DokumenKaryawanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function indexByKaryawan(Request $request, string $idKaryawan): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $rows = $this->service->listByKaryawan($idKaryawan, $idPerusahaan);

        return ApiResponse::success(DokumenKaryawanResource::collection($rows));
    }

    public function store(StoreDokumenKaryawanRequest $request, string $idKaryawan): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($idKaryawan, $idPerusahaan, $request->validated(), $request->file('file'));

        return ApiResponse::success(new DokumenKaryawanResource($record), 'Dokumen karyawan berhasil dibuat', 201);
    }

    public function update(UpdateDokumenKaryawanRequest $request, string $idKaryawan, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $idPerusahaan, $request->validated(), $request->file('file'));

        return ApiResponse::success(new DokumenKaryawanResource($record), 'Dokumen karyawan berhasil diperbarui');
    }

    public function destroy(Request $request, string $idKaryawan, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);

        return ApiResponse::success(null, 'Dokumen karyawan berhasil dihapus');
    }
}
