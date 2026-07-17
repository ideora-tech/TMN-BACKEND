<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Helpers\ApiResponse;
use App\Modules\DokumenArmada\Requests\StoreDokumenArmadaRequest;
use App\Modules\DokumenArmada\Requests\UpdateDokumenArmadaRequest;
use App\Modules\DokumenArmada\Resources\DokumenArmadaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DokumenArmadaController extends Controller
{
    public function __construct(private readonly DokumenArmadaService $service) {}

    public function indexByArmada(Request $request, string $idArmada): JsonResponse
    {
        $result = $this->service->listByArmada(
            $idArmada,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 100)
        );
        return ApiResponse::paginated(
            DokumenArmadaResource::collection($result['data']),
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
            $request->get('jenis_dokumen')
        );

        return ApiResponse::paginated(
            DokumenArmadaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function store(StoreDokumenArmadaRequest $request, string $idArmada): JsonResponse
    {
        $record = $this->service->create($idArmada, $request->validated(), $request->file('file'));
        return ApiResponse::success(new DokumenArmadaResource($record), 'Dokumen armada berhasil dibuat', 201);
    }

    public function update(UpdateDokumenArmadaRequest $request, string $idArmada, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), $request->file('file'));
        return ApiResponse::success(new DokumenArmadaResource($record), 'Dokumen armada berhasil diperbarui');
    }

    public function destroy(string $idArmada, string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Dokumen armada berhasil dihapus');
    }

    public function expiring(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $days = (int) $request->get('days', 30);
        $records = $this->service->getExpiring($idPerusahaan, $days);
        return ApiResponse::success(DokumenArmadaResource::collection($records));
    }
}
