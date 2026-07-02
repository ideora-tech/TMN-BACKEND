<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor;

use App\Helpers\ApiResponse;
use App\Modules\LokasiKantor\Requests\StoreLokasiKantorRequest;
use App\Modules\LokasiKantor\Requests\UpdateLokasiKantorRequest;
use App\Modules\LokasiKantor\Resources\LokasiKantorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LokasiKantorController extends Controller
{
    public function __construct(private readonly LokasiKantorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            LokasiKantorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new LokasiKantorResource($this->service->findOrFail($id)));
    }

    public function store(StoreLokasiKantorRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new LokasiKantorResource($record), 'Lokasi kantor berhasil dibuat', 201);
    }

    public function update(UpdateLokasiKantorRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new LokasiKantorResource($record), 'Lokasi kantor berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Lokasi kantor berhasil dihapus');
    }
}
