<?php

declare(strict_types=1);

namespace App\Modules\RekeningVendor;

use App\Helpers\ApiResponse;
use App\Modules\RekeningVendor\Requests\StoreRekeningVendorRequest;
use App\Modules\RekeningVendor\Requests\UpdateRekeningVendorRequest;
use App\Modules\RekeningVendor\Resources\RekeningVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RekeningVendorController extends Controller
{
    public function __construct(private readonly RekeningVendorService $service) {}

    public function indexByVendor(Request $request, string $idVendor): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->listByVendor(
            $idVendor,
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 100)
        );
        return ApiResponse::paginated(
            RekeningVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function store(StoreRekeningVendorRequest $request, string $idVendor): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($idVendor, $idPerusahaan, $request->validated());
        return ApiResponse::success(new RekeningVendorResource($record), 'Rekening vendor berhasil dibuat', 201);
    }

    public function update(UpdateRekeningVendorRequest $request, string $idVendor, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $idVendor, $idPerusahaan, $request->validated());
        return ApiResponse::success(new RekeningVendorResource($record), 'Rekening vendor berhasil diperbarui');
    }

    public function destroy(Request $request, string $idVendor, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idVendor, $idPerusahaan);
        return ApiResponse::success(null, 'Rekening vendor berhasil dihapus');
    }
}
