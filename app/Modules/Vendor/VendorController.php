<?php

declare(strict_types=1);

namespace App\Modules\Vendor;

use App\Helpers\ApiResponse;
use App\Modules\Vendor\Exports\VendorTemplateExport;
use App\Modules\Vendor\Requests\ImportVendorRequest;
use App\Modules\Vendor\Requests\StoreVendorRequest;
use App\Modules\Vendor\Requests\UpdateVendorRequest;
use App\Modules\Vendor\Resources\VendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VendorController extends Controller
{
    public function __construct(private readonly VendorService $service) {}

    public function downloadTemplate(): BinaryFileResponse
    {
        return Excel::download(new VendorTemplateExport(), 'template-import-vendor.xlsx');
    }

    public function import(ImportVendorRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->import($request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'Import vendor selesai diproses');
    }

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
            VendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new VendorResource($this->service->findOrFail($id, $idPerusahaan)));
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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Vendor berhasil dihapus');
    }
}
