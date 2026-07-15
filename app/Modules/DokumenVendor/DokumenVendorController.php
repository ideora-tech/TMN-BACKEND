<?php

declare(strict_types=1);

namespace App\Modules\DokumenVendor;

use App\Helpers\ApiResponse;
use App\Modules\DokumenVendor\Requests\StoreDokumenVendorRequest;
use App\Modules\DokumenVendor\Requests\UpdateDokumenVendorRequest;
use App\Modules\DokumenVendor\Resources\DokumenVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DokumenVendorController extends Controller
{
    public function __construct(private readonly DokumenVendorService $service) {}

    public function indexByVendor(Request $request, string $idVendor): JsonResponse
    {
        $result = $this->service->listByVendor(
            $idVendor,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 100)
        );
        return ApiResponse::paginated(
            DokumenVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function store(StoreDokumenVendorRequest $request, string $idVendor): JsonResponse
    {
        $record = $this->service->create($idVendor, $request->validated(), $request->file('file'));
        return ApiResponse::success(new DokumenVendorResource($record), 'Dokumen vendor berhasil dibuat', 201);
    }

    public function update(UpdateDokumenVendorRequest $request, string $idVendor, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $idVendor, $idPerusahaan, $request->validated(), $request->file('file'));
        return ApiResponse::success(new DokumenVendorResource($record), 'Dokumen vendor berhasil diperbarui');
    }

    public function destroy(Request $request, string $idVendor, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idVendor, $idPerusahaan);
        return ApiResponse::success(null, 'Dokumen vendor berhasil dihapus');
    }

    public function expiring(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $days = (int) $request->get('days', 30);
        $records = $this->service->getExpiring($idPerusahaan, $days);
        return ApiResponse::success(DokumenVendorResource::collection($records));
    }
}
