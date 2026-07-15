<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor;

use App\Helpers\ApiResponse;
use App\Modules\SupirVendor\Requests\StoreSupirVendorRequest;
use App\Modules\SupirVendor\Requests\UpdateSupirVendorRequest;
use App\Modules\SupirVendor\Resources\SupirVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SupirVendorController extends Controller
{
    public function __construct(private readonly SupirVendorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_vendor')
        );

        return ApiResponse::paginated(
            SupirVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new SupirVendorResource($this->service->findOrFail($id, $idPerusahaan)));
    }

    public function store(StoreSupirVendorRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($request->validated(), $idPerusahaan);
        return ApiResponse::success(new SupirVendorResource($record), 'Supir vendor berhasil dibuat', 201);
    }

    public function update(UpdateSupirVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new SupirVendorResource($record), 'Supir vendor berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Supir vendor berhasil dihapus');
    }
}
