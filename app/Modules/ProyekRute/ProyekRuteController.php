<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute;

use App\Helpers\ApiResponse;
use App\Modules\ProyekRute\Requests\StoreProyekRuteRequest;
use App\Modules\ProyekRute\Requests\UpdateProyekRuteRequest;
use App\Modules\ProyekRute\Resources\ProyekRuteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ProyekRuteController extends Controller
{
    public function __construct(private readonly ProyekRuteService $service) {}

    public function index(string $idProyek): JsonResponse
    {
        return ApiResponse::success(
            ProyekRuteResource::collection($this->service->listByProyek($idProyek))
        );
    }

    public function store(StoreProyekRuteRequest $request, string $idProyek): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($idProyek, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new ProyekRuteResource($record), 'Rute proyek berhasil ditambahkan', 201);
    }

    public function update(UpdateProyekRuteRequest $request, string $idProyek, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($idProyek, $id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new ProyekRuteResource($record), 'Rute proyek berhasil diperbarui');
    }

    public function destroy(string $idProyek, string $id): JsonResponse
    {
        $this->service->delete($idProyek, $id);
        return ApiResponse::success(null, 'Rute proyek berhasil dihapus');
    }
}
