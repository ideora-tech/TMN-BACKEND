<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Helpers\ApiResponse;
use App\Modules\Jabatan\Requests\StoreJabatanRequest;
use App\Modules\Jabatan\Requests\UpdateJabatanRequest;
use App\Modules\Jabatan\Resources\JabatanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JabatanController extends Controller
{
    public function __construct(private readonly JabatanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $idDepartemen = $request->get('id_departemen');

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $idDepartemen !== null ? (string) $idDepartemen : null
        );

        return ApiResponse::paginated(
            JabatanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new JabatanResource($this->service->findOrFail($id)));
    }

    public function store(StoreJabatanRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new JabatanResource($record), 'Jabatan berhasil dibuat', 201);
    }

    public function update(UpdateJabatanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new JabatanResource($record), 'Jabatan berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Jabatan berhasil dihapus');
    }
}
