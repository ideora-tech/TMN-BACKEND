<?php

declare(strict_types=1);

namespace App\Modules\RekeningPerusahaan;

use App\Helpers\ApiResponse;
use App\Modules\RekeningPerusahaan\Requests\StoreRekeningPerusahaanRequest;
use App\Modules\RekeningPerusahaan\Requests\UpdateRekeningPerusahaanRequest;
use App\Modules\RekeningPerusahaan\Resources\RekeningPerusahaanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RekeningPerusahaanController extends Controller
{
    public function __construct(private readonly RekeningPerusahaanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');

        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            max(1, (int) $request->get('page', 1)),
            min(100, max(1, (int) $request->get('limit', 10))),
            is_string($search) ? $search : null,
        );

        return ApiResponse::paginated(RekeningPerusahaanResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new RekeningPerusahaanResource(
            $this->service->findOrFail($id, (string) $request->user()->id_perusahaan),
        ));
    }

    public function store(StoreRekeningPerusahaanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new RekeningPerusahaanResource($record), 'Rekening berhasil dibuat', 201);
    }

    public function update(UpdateRekeningPerusahaanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new RekeningPerusahaanResource($record), 'Rekening berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Rekening berhasil dihapus');
    }
}
