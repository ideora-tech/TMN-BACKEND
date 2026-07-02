<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan;

use App\Helpers\ApiResponse;
use App\Modules\Perusahaan\Requests\StorePerusahaanRequest;
use App\Modules\Perusahaan\Requests\UpdatePerusahaanRequest;
use App\Modules\Perusahaan\Resources\PerusahaanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PerusahaanController extends Controller
{
    public function __construct(private readonly PerusahaanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            PerusahaanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new PerusahaanResource($this->service->findOrFail($id)));
    }

    public function store(StorePerusahaanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(new PerusahaanResource($record), 'Perusahaan berhasil dibuat', 201);
    }

    public function update(UpdatePerusahaanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new PerusahaanResource($record), 'Perusahaan berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Perusahaan berhasil dihapus');
    }
}
