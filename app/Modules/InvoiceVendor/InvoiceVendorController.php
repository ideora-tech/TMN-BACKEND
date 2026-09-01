<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor;

use App\Helpers\ApiResponse;
use App\Modules\InvoiceVendor\Requests\StoreInvoiceVendorRequest;
use App\Modules\InvoiceVendor\Requests\UpdateInvoiceVendorRequest;
use App\Modules\InvoiceVendor\Resources\InvoiceVendorResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function exportPdf(Request $request, string $id): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $this->service->detail($id, $idPerusahaan);

        $pdf = Pdf::loadView('exports.invoice-vendor', [
            'd'          => $data,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan($idPerusahaan),
        ]);

        return $pdf->download('invoice-vendor-' . $this->namaFileAman($data['nomor_invoice']) . '.pdf');
    }

    private function namaFileAman(?string $nomor): string
    {
        $bersih = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $nomor);
        return trim((string) $bersih, '-') ?: 'invoice-vendor';
    }

    private function logoBase64(): ?string
    {
        $path = public_path('img/logo/logo-sli.png');
        if (!is_file($path)) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }

    public function tripSiapTagih(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_kontrak_vendor' => ['required', 'string', 'max:36'],
            'id_proyek'         => ['sometimes', 'nullable', 'string', 'max:36'],
            'dari'              => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sampai'            => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $rows = $this->service->tripSiapTagih(
            $validated['id_kontrak_vendor'],
            (string) $request->user()->id_perusahaan,
            $validated['dari'] ?? null,
            $validated['sampai'] ?? null,
            $validated['id_proyek'] ?? null,
        );

        return ApiResponse::success($rows);
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

    public function ajukanApproval(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->ajukanApproval($id, (string) $request->user()->id_pengguna, $idPerusahaan);
        return ApiResponse::success(new InvoiceVendorResource($record), 'Invoice diajukan untuk approval');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Invoice vendor berhasil dihapus');
    }
}
