<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Helpers\ApiResponse;
use App\Modules\JenisPerawatan\Requests\StoreJenisPerawatanRequest;
use App\Modules\JenisPerawatan\Requests\UpdateJenisPerawatanRequest;
use App\Modules\JenisPerawatan\Resources\JenisPerawatanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JenisPerawatanController extends Controller
{
    public function __construct(private readonly JenisPerawatanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search')
        );

        return ApiResponse::paginated(
            JenisPerawatanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new JenisPerawatanResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StoreJenisPerawatanRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new JenisPerawatanResource($record), 'Jenis perawatan berhasil dibuat', 201);
    }

    public function update(UpdateJenisPerawatanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new JenisPerawatanResource($record), 'Jenis perawatan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Jenis perawatan berhasil dihapus');
    }
}
