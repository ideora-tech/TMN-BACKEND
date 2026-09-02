<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor;

use App\Helpers\ApiResponse;
use App\Modules\PembayaranVendor\Requests\StorePembayaranVendorRequest;
use App\Modules\PembayaranVendor\Resources\PembayaranVendorResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function ajukan(Request $request, string $idInvoice): JsonResponse
    {
        $validated = $request->validate([
            'nominal' => ['required', 'numeric', 'min:1'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $record = $this->service->ajukanPembayaran(
            $idInvoice,
            (string) $request->user()->id_perusahaan,
            (float) $validated['nominal'],
            $validated['catatan'] ?? null,
        );

        return ApiResponse::success([
            'id_pengajuan'    => $record->id_pengajuan,
            'nomor_pengajuan' => $record->nomor_pengajuan,
            'status'          => $record->status,
            'nominal'         => (float) $record->nominal,
        ], 'Pengajuan pembayaran dibuat — pantau prosesnya di menu Proses Pembayaran', 201);
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

    public function exportPdf(Request $request, string $idInvoice, string $id): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $this->service->dataCetak($id, $idInvoice, $idPerusahaan);

        $pdf = Pdf::loadView('exports.invoice-vendor-termin', [
            'p'          => $data,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan($idPerusahaan),
        ]);

        $tanggal = $data->tanggal_bayar ? date('Ymd', strtotime((string) $data->tanggal_bayar)) : 'termin';
        return $pdf->download('kwitansi-' . $this->namaFileAman($data->nomor_invoice) . '-' . $tanggal . '.pdf');
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
}
