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
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->get('aktif'),
            $this->batasPerusahaan($request) === null,
        );

        return ApiResponse::paginated(
            PeranResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new PeranResource($this->service->findOrFail($id, $this->batasPerusahaan($request))));
    }

    public function store(StorePeranRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data, $this->batasPerusahaan($request));
        return ApiResponse::success(new PeranResource($record), 'Peran berhasil dibuat', 201);
    }

    public function update(UpdatePeranRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), $this->batasPerusahaan($request));
        return ApiResponse::success(new PeranResource($record), 'Peran berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, $this->batasPerusahaan($request));
        return ApiResponse::success(null, 'Peran berhasil dihapus');
    }

    private function batasPerusahaan(Request $request): ?string
    {
        return $request->user()->kode_peran === 'SUPERADMIN' ? null : (string) $request->user()->id_perusahaan;
    }
}
