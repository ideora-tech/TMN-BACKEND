<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan;

use App\Helpers\ApiResponse;
use App\Modules\JenisKendaraan\Requests\StoreJenisKendaraanRequest;
use App\Modules\JenisKendaraan\Requests\UpdateJenisKendaraanRequest;
use App\Modules\JenisKendaraan\Resources\JenisKendaraanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JenisKendaraanController extends Controller
{
    public function __construct(private readonly JenisKendaraanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            JenisKendaraanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new JenisKendaraanResource($this->service->findOrFail($id)));
    }

    public function store(StoreJenisKendaraanRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new JenisKendaraanResource($record), 'Jenis kendaraan berhasil dibuat', 201);
    }

    public function update(UpdateJenisKendaraanRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new JenisKendaraanResource($record), 'Jenis kendaraan berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Jenis kendaraan berhasil dihapus');
    }
}
