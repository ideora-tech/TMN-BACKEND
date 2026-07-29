<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor;

use App\Helpers\ApiResponse;
use App\Modules\PembayaranVendor\Requests\StorePembayaranVendorRequest;
use App\Modules\PembayaranVendor\Resources\PembayaranVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PembayaranVendorController extends Controller
{
    public function __construct(private readonly PembayaranVendorService $service) {}

    public function indexByInvoice(Request $request, string $idInvoice): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $records = $this->service->listByInvoice($idInvoice, $idPerusahaan);
        return ApiResponse::success(PembayaranVendorResource::collection($records));
    }

    public function store(StorePembayaranVendorRequest $request, string $idInvoice): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($idInvoice, $idPerusahaan, $request->validated(), $request->file('bukti'));
        return ApiResponse::success(new PembayaranVendorResource($record), 'Pembayaran vendor berhasil dicatat', 201);
    }

    public function destroy(Request $request, string $idInvoice, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idInvoice, $idPerusahaan);
        return ApiResponse::success(null, 'Pembayaran vendor berhasil dihapus');
    }
}
