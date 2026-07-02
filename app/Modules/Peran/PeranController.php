<?php

declare(strict_types=1);

namespace App\Modules\Peran;

use App\Helpers\ApiResponse;
use App\Modules\Peran\Requests\StorePeranRequest;
use App\Modules\Peran\Requests\UpdatePeranRequest;
use App\Modules\Peran\Resources\PeranResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PeranController extends Controller
{
    public function __construct(private readonly PeranService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            PeranResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new PeranResource($this->service->findOrFail($id)));
    }

    public function store(StorePeranRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new PeranResource($record), 'Peran berhasil dibuat', 201);
    }

    public function update(UpdatePeranRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new PeranResource($record), 'Peran berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Peran berhasil dihapus');
    }
}
