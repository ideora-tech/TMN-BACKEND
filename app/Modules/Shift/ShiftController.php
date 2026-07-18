<?php

declare(strict_types=1);

namespace App\Modules\Shift;

use App\Helpers\ApiResponse;
use App\Modules\Shift\Requests\StoreShiftRequest;
use App\Modules\Shift\Requests\UpdateShiftRequest;
use App\Modules\Shift\Resources\ShiftResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            ShiftResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new ShiftResource($this->service->findOrFail($id)));
    }

    public function store(StoreShiftRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new ShiftResource($record), 'Shift berhasil dibuat', 201);
    }

    public function update(UpdateShiftRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new ShiftResource($record), 'Shift berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Shift berhasil dihapus');
    }
}
