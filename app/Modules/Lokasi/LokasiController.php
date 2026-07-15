<?php

declare(strict_types=1);

namespace App\Modules\Lokasi;

use App\Helpers\ApiResponse;
use App\Modules\Lokasi\Requests\StoreLokasiRequest;
use App\Modules\Lokasi\Requests\UpdateLokasiRequest;
use App\Modules\Lokasi\Resources\LokasiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LokasiController extends Controller
{
    public function __construct(private readonly LokasiService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search')
        );

        return ApiResponse::paginated(
            LokasiResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new LokasiResource($this->service->findOrFail($id, $idPerusahaan)));
    }

    public function store(StoreLokasiRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new LokasiResource($record), 'Lokasi berhasil dibuat', 201);
    }

    public function update(UpdateLokasiRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new LokasiResource($record), 'Lokasi berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Lokasi berhasil dihapus');
    }
}
