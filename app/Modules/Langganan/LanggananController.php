<?php

declare(strict_types=1);

namespace App\Modules\Langganan;

use App\Helpers\ApiResponse;
use App\Modules\Langganan\Requests\StoreLanggananRequest;
use App\Modules\Langganan\Requests\UpdateLanggananRequest;
use App\Modules\Langganan\Resources\LanggananResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LanggananController extends Controller
{
    public function __construct(private readonly LanggananService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            LanggananResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new LanggananResource($this->service->findOrFail($id)));
    }

    public function store(StoreLanggananRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new LanggananResource($record), 'Langganan berhasil dibuat', 201);
    }

    public function update(UpdateLanggananRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new LanggananResource($record), 'Langganan berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Langganan berhasil dihapus');
    }
}
