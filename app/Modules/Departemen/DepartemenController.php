<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Helpers\ApiResponse;
use App\Modules\Departemen\Requests\StoreDepartemenRequest;
use App\Modules\Departemen\Requests\UpdateDepartemenRequest;
use App\Modules\Departemen\Resources\DepartemenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DepartemenController extends Controller
{
    public function __construct(private readonly DepartemenService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            DepartemenResource::collection($result['data']),
            $result['meta']
        );
    }

    public function tree(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $this->service->tree($idPerusahaan);
        return ApiResponse::success(DepartemenResource::collection($data));
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new DepartemenResource($this->service->findOrFail($id)));
    }

    public function store(StoreDepartemenRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new DepartemenResource($record), 'Departemen berhasil dibuat', 201);
    }

    public function update(UpdateDepartemenRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new DepartemenResource($record), 'Departemen berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Departemen berhasil dihapus');
    }
}
