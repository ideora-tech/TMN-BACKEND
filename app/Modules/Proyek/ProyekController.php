<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Helpers\ApiResponse;
use App\Modules\Proyek\Requests\StoreProyekRequest;
use App\Modules\Proyek\Requests\UpdateProyekRequest;
use App\Modules\Proyek\Requests\UpdateStatusProyekRequest;
use App\Modules\Proyek\Resources\ProyekResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProyekController extends Controller
{
    public function __construct(private readonly ProyekService $service) {}

    public function index(Request $request): JsonResponse
    {
        $page  = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 10);

        if ($request->filled('id_klien')) {
            $result = $this->service->listByKlien((string) $request->get('id_klien'), $page, $limit);
        } else {
            $idPerusahaan = (string) $request->user()->id_perusahaan;
            $result = $this->service->list($idPerusahaan, $page, $limit);
        }

        return ApiResponse::paginated(
            ProyekResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new ProyekResource($this->service->findOrFail($id)));
    }

    public function store(StoreProyekRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new ProyekResource($record), 'Proyek berhasil dibuat', 201);
    }

    public function update(UpdateProyekRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new ProyekResource($record), 'Proyek berhasil diperbarui');
    }

    public function updateStatus(UpdateStatusProyekRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateStatus($id, $request->validated()['status']);
        return ApiResponse::success(new ProyekResource($record), 'Status proyek berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Proyek berhasil dihapus');
    }
}
