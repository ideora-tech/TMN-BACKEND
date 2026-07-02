<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi;

use App\Helpers\ApiResponse;
use App\Modules\Rekonsiliasi\Requests\StoreRekonsiliasiRequest;
use App\Modules\Rekonsiliasi\Requests\UpdateRekonsiliasiRequest;
use App\Modules\Rekonsiliasi\Resources\RekonsiliasiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RekonsiliasiController extends Controller
{
    public function __construct(private readonly RekonsiliasiService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            RekonsiliasiResource::collection($result['data']),
            $result['meta']
        );
    }

    public function indexByFaktur(Request $request, string $idFaktur): JsonResponse
    {
        $result = $this->service->listByFaktur(
            $idFaktur,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            RekonsiliasiResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new RekonsiliasiResource($this->service->findOrFail($id)));
    }

    public function store(StoreRekonsiliasiRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(new RekonsiliasiResource($record), 'Rekonsiliasi berhasil dibuat', 201);
    }

    public function update(UpdateRekonsiliasiRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new RekonsiliasiResource($record), 'Rekonsiliasi berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Rekonsiliasi berhasil dihapus');
    }
}
