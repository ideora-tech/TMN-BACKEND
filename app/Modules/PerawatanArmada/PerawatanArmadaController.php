<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Helpers\ApiResponse;
use App\Modules\PerawatanArmada\Requests\StorePerawatanArmadaRequest;
use App\Modules\PerawatanArmada\Requests\UpdatePerawatanArmadaRequest;
use App\Modules\PerawatanArmada\Resources\PerawatanArmadaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PerawatanArmadaController extends Controller
{
    public function __construct(private readonly PerawatanArmadaService $service) {}

    public function indexByArmada(Request $request, string $idArmada): JsonResponse
    {
        $result = $this->service->listByArmada(
            $idArmada,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            PerawatanArmadaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listByPerusahaan(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_armada'),
            $request->get('status'),
            $request->boolean('jatuh_tempo'),
        );

        return ApiResponse::paginated(
            PerawatanArmadaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $idArmada, string $id): JsonResponse
    {
        return ApiResponse::success(new PerawatanArmadaResource($this->service->findOrFail($id)));
    }

    public function store(StorePerawatanArmadaRequest $request, string $idArmada): JsonResponse
    {
        $record = $this->service->create($idArmada, $request->validated());
        return ApiResponse::success(new PerawatanArmadaResource($record), 'Perawatan armada berhasil dibuat', 201);
    }

    public function update(UpdatePerawatanArmadaRequest $request, string $idArmada, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new PerawatanArmadaResource($record), 'Perawatan armada berhasil diperbarui');
    }

    public function destroy(string $idArmada, string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Perawatan armada berhasil dihapus');
    }

    public function prediksiPerawatan(Request $request, string $idArmada): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $days = (int) $request->get('days', 30);

        return ApiResponse::success($this->service->prediksiPerawatan($idArmada, $idPerusahaan, $days));
    }
}
