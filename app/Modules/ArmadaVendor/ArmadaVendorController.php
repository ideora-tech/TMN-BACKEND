<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor;

use App\Helpers\ApiResponse;
use App\Modules\ArmadaVendor\Requests\StoreArmadaVendorRequest;
use App\Modules\ArmadaVendor\Requests\UpdateArmadaVendorRequest;
use App\Modules\ArmadaVendor\Resources\ArmadaVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ArmadaVendorController extends Controller
{
    public function __construct(private readonly ArmadaVendorService $service) {}

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
            ArmadaVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new ArmadaVendorResource($this->service->findOrFail($id, $idPerusahaan)));
    }

    public function store(StoreArmadaVendorRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($request->validated(), $idPerusahaan);
        return ApiResponse::success(new ArmadaVendorResource($record), 'Armada vendor berhasil dibuat', 201);
    }

    public function update(UpdateArmadaVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new ArmadaVendorResource($record), 'Armada vendor berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Armada vendor berhasil dihapus');
    }
}
