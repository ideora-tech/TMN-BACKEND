<?php

declare(strict_types=1);

namespace App\Modules\Armada;

use App\Helpers\ApiResponse;
use App\Modules\Armada\Requests\StoreArmadaRequest;
use App\Modules\Armada\Requests\UpdateArmadaRequest;
use App\Modules\Armada\Resources\ArmadaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ArmadaController extends Controller
{
    public function __construct(private readonly ArmadaService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $status = $request->get('status') !== null ? (string) $request->get('status') : null;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $status
        );

        return ApiResponse::paginated(
            ArmadaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new ArmadaResource($this->service->findOrFail($id)));
    }

    public function store(StoreArmadaRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new ArmadaResource($record), 'Armada berhasil dibuat', 201);
    }

    public function update(UpdateArmadaRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new ArmadaResource($record), 'Armada berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Armada berhasil dihapus');
    }
}
