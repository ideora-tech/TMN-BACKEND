<?php

declare(strict_types=1);

namespace App\Modules\Vendor;

use App\Helpers\ApiResponse;
use App\Modules\Vendor\Requests\StoreVendorRequest;
use App\Modules\Vendor\Requests\UpdateVendorRequest;
use App\Modules\Vendor\Resources\VendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VendorController extends Controller
{
    public function __construct(private readonly VendorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            VendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new VendorResource($this->service->findOrFail($id)));
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new VendorResource($record), 'Vendor berhasil dibuat', 201);
    }

    public function update(UpdateVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new VendorResource($record), 'Vendor berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Vendor berhasil dihapus');
    }
}
