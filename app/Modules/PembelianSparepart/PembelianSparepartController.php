<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart;

use App\Helpers\ApiResponse;
use App\Modules\PembelianSparepart\Exports\LaporanPembelianExport;
use App\Modules\PembelianSparepart\Requests\RealisasiPembelianRequest;
use App\Modules\PembelianSparepart\Requests\StorePembelianSparepartRequest;
use App\Modules\PembelianSparepart\Requests\UpdatePembelianSparepartRequest;
use App\Modules\PembelianSparepart\Requests\UploadBuktiPembelianRequest;
use App\Modules\PembelianSparepart\Resources\PembelianSparepartResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PembelianSparepartController extends Controller
{
    public function __construct(private readonly PembelianSparepartService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->only(['status', 'id_supplier', 'dari', 'sampai', 'search'])
        );
        return ApiResponse::paginated(PembelianSparepartResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record));
    }

    public function store(StorePembelianSparepartRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record), 'Pengajuan pembelian berhasil dibuat', 201);
    }

    public function update(UpdatePembelianSparepartRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record), 'Pengajuan pembelian berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Pengajuan pembelian berhasil dihapus');
    }

    public function approveManager(Request $request, string $id): JsonResponse
    {
        $record = $this->service->approveManager($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record), 'Pengajuan disetujui manager');
    }

    public function approveFinance(Request $request, string $id): JsonResponse
    {
        $record = $this->service->approveFinance($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record), 'Pengajuan disetujui finance');
    }

    public function tolak(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string']]);
        $record = $this->service->tolak(
            $id,
            $data['alasan'],
            (string) $request->user()->kode_peran,
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(new PembelianSparepartResource($record), 'Pengajuan ditolak');
    }

    public function tambahBukti(UploadBuktiPembelianRequest $request, string $id): JsonResponse
    {
        $record = $this->service->tambahBukti($id, $request->file('bukti'), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record), 'Bukti berhasil diunggah');
    }

    public function hapusBukti(Request $request, string $id, string $idBukti): JsonResponse
    {
        $record = $this->service->hapusBukti($id, $idBukti, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record), 'Bukti berhasil dihapus');
    }

    public function realisasi(RealisasiPembelianRequest $request, string $id): JsonResponse
    {
        $record = $this->service->realisasi($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record), 'Realisasi pembelian tersimpan, stok diperbarui');
    }

    public function lunas(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['tanggal_pembayaran' => ['required', 'date']]);
        $record = $this->service->tandaiLunas($id, $data['tanggal_pembayaran'], (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PembelianSparepartResource($record), 'Pembelian ditandai lunas');
    }

    public function laporan(Request $request): JsonResponse
    {
        $hasil = $this->service->laporan(
            (string) $request->user()->id_perusahaan,
            $request->get('dari'),
            $request->get('sampai')
        );
        return ApiResponse::success($hasil);
    }

    public function exportLaporanExcel(Request $request): BinaryFileResponse
    {
        $dari = $request->get('dari');
        $sampai = $request->get('sampai');
        $laporan = $this->service->laporan((string) $request->user()->id_perusahaan, $dari, $sampai);

        return Excel::download(
            new LaporanPembelianExport($laporan, $dari, $sampai),
            'laporan-pembelian-sparepart-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportLaporanPdf(Request $request): Response
    {
        $dari = $request->get('dari');
        $sampai = $request->get('sampai');
        $laporan = $this->service->laporan((string) $request->user()->id_perusahaan, $dari, $sampai);

        $pdf = Pdf::loadView('exports.laporan-pembelian-sparepart', [
            'laporan'    => $laporan,
            'dari'       => $dari,
            'sampai'     => $sampai,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan((string) $request->user()->id_perusahaan),
        ]);

        return $pdf->download('laporan-pembelian-sparepart-' . date('Ymd') . '.pdf');
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
