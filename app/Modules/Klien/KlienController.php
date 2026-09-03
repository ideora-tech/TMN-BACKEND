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
        $search = $request->get('search') !== null && $request->get('search') !== '' ? (string) $request->get('search') : null;
        $aktif  = $request->get('aktif') !== null && $request->get('aktif') !== '' ? (string) $request->get('aktif') : null;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $search,
            $aktif
        );

        return ApiResponse::paginated(
            KlienResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new KlienResource($this->service->findOrFail($id, $idPerusahaan)));
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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
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
