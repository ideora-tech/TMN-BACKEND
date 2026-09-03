<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Helpers\ApiResponse;
use App\Modules\Karyawan\Requests\StoreKaryawanRequest;
use App\Modules\Karyawan\Requests\UpdateKaryawanRequest;
use App\Modules\Karyawan\Resources\KaryawanResource;
use App\Modules\KaryawanExit\Resources\KaryawanExitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KaryawanController extends Controller
{
    public function __construct(private readonly KaryawanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $status = $request->get('status') !== null ? (string) $request->get('status') : null;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $status,
            $request->get('search')
        );

        return ApiResponse::paginated(
            KaryawanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new KaryawanResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StoreKaryawanRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KaryawanResource($record), 'Karyawan berhasil dibuat', 201);
    }

    public function update(UpdateKaryawanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new KaryawanResource($record), 'Karyawan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Karyawan berhasil dihapus');
    }

    public function exitHistory(string $id): JsonResponse
    {
        $history = $this->service->exitHistory($id);
        return ApiResponse::success(KaryawanExitResource::collection($history));
    }

    public function riwayatJabatan(string $id): JsonResponse
    {
        return ApiResponse::success($this->service->riwayatJabatan($id));
    }
}
