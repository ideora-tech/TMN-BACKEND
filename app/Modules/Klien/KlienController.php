<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Helpers\ApiResponse;
use App\Modules\Klien\Requests\StoreKlienRequest;
use App\Modules\Klien\Requests\UpdateKlienRequest;
use App\Modules\Klien\Resources\KlienResource;
use App\Modules\Proyek\Resources\ProyekResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KlienController extends Controller
{
    public function __construct(private readonly KlienService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            KlienResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new KlienResource($this->service->findOrFail($id)));
    }

    public function store(StoreKlienRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KlienResource($record), 'Klien berhasil dibuat', 201);
    }

    public function update(UpdateKlienRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new KlienResource($record), 'Klien berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Klien berhasil dihapus');
    }

    public function riwayatProyek(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->riwayatProyek(
            $id,
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            ProyekResource::collection($result['data']),
            $result['meta']
        );
    }
}
