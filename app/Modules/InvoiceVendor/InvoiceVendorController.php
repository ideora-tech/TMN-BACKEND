<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor;

use App\Helpers\ApiResponse;
use App\Modules\InvoiceVendor\Requests\StoreInvoiceVendorRequest;
use App\Modules\InvoiceVendor\Requests\UpdateInvoiceVendorRequest;
use App\Modules\InvoiceVendor\Requests\VerifikasiInvoiceVendorRequest;
use App\Modules\InvoiceVendor\Resources\InvoiceVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class InvoiceVendorController extends Controller
{
    public function __construct(private readonly InvoiceVendorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->filled('search') ? (string) $request->get('search') : null,
            $request->filled('status') ? (string) $request->get('status') : null,
            $request->filled('status_pembayaran') ? (string) $request->get('status_pembayaran') : null,
            $request->filled('id_vendor') ? (string) $request->get('id_vendor') : null,
        );

        return ApiResponse::paginated(
            InvoiceVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function monitoring(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->monitoring($idPerusahaan));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->detail($id, $idPerusahaan));
    }

    public function store(StoreInvoiceVendorRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($idPerusahaan, $request->validated());
        return ApiResponse::success(new InvoiceVendorResource($record), 'Invoice vendor berhasil dibuat', 201);
    }

    public function update(UpdateInvoiceVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $idPerusahaan, $request->validated());
        return ApiResponse::success(new InvoiceVendorResource($record), 'Invoice vendor berhasil diperbarui');
    }

    public function verifikasi(VerifikasiInvoiceVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $request->validated();

        $record = $this->service->verifikasi(
            $id,
            $idPerusahaan,
            $data['aksi'],
            $data['catatan'] ?? null,
            (string) $request->user()->id_pengguna
        );

        $pesan = $data['aksi'] === 'verifikasi'
            ? 'Invoice vendor berhasil diverifikasi'
            : 'Invoice vendor berhasil ditolak';

        return ApiResponse::success(new InvoiceVendorResource($record), $pesan);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Invoice vendor berhasil dihapus');
    }
}
