<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian;

use App\Helpers\ApiResponse;
use App\Modules\PermintaanPembelian\Exports\LaporanPengadaanExport;
use App\Modules\PermintaanPembelian\Requests\BatalPermintaanPembelianRequest;
use App\Modules\PermintaanPembelian\Requests\DibeliPermintaanPembelianRequest;
use App\Modules\PermintaanPembelian\Requests\LaporanPengadaanRequest;
use App\Modules\PermintaanPembelian\Requests\RealisasiSparepartPermintaanPembelianRequest;
use App\Modules\PermintaanPembelian\Requests\StorePermintaanPembelianRequest;
use App\Modules\PermintaanPembelian\Requests\TerimaPermintaanPembelianRequest;
use App\Modules\PermintaanPembelian\Requests\UpdatePermintaanPembelianRequest;
use App\Modules\PermintaanPembelian\Requests\UploadBuktiPermintaanPembelianRequest;
use App\Modules\PermintaanPembelian\Resources\PermintaanPembelianResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PermintaanPembelianController extends Controller
{
    public function __construct(private readonly PermintaanPembelianService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->only(['status', 'tipe', 'search', 'id_departemen', 'dari', 'sampai']);
        if ($request->boolean('milik_saya')) {
            $filter['id_pengaju'] = (string) $request->user()->id_pengguna;
        }
        $result = $this->service->list((string) $request->user()->id_perusahaan, (int) $request->get('page', 1), (int) $request->get('limit', 10), $filter);
        return ApiResponse::paginated(PermintaanPembelianResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new PermintaanPembelianResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StorePermintaanPembelianRequest $request): JsonResponse
    {
        $record = $this->service->create($request->safe()->except('bukti'), $request->file('bukti', []), (string) $request->user()->id_perusahaan, (string) $request->user()->id_pengguna);
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Permintaan pembelian berhasil diajukan', 201);
    }

    public function update(UpdatePermintaanPembelianRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), ...$this->aktor($request));
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Permintaan pembelian berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, ...$this->aktor($request));
        return ApiResponse::success(null, 'Permintaan pembelian berhasil dihapus');
    }

    public function proses(Request $request, string $id): JsonResponse
    {
        $record = $this->service->proses($id, ...$this->aktor($request));
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Permintaan mulai diproses Pengadaan');
    }

    public function dibeli(DibeliPermintaanPembelianRequest $request, string $id): JsonResponse
    {
        $record = $this->service->dibeli($id, $request->validated(), ...$this->aktor($request));
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Permintaan ditandai dibeli, pengajuan pembayaran dibuat');
    }

    public function terima(TerimaPermintaanPembelianRequest $request, string $id): JsonResponse
    {
        $record = $this->service->terima($id, $request->validated(), ...$this->aktor($request));
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Penerimaan dikonfirmasi, stok diperbarui');
    }

    public function realisasiSparepart(RealisasiSparepartPermintaanPembelianRequest $request, string $id): JsonResponse
    {
        [$idPerusahaan, $idPengguna, $kodePeran] = $this->aktor($request);
        $record = $this->service->realisasiSparepart($id, $request->validated(), $idPerusahaan, $idPengguna, $kodePeran);
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Realisasi tersimpan, stok diperbarui');
    }

    public function laporan(LaporanPengadaanRequest $request): JsonResponse
    {
        $hasil = $this->service->laporan(
            (string) $request->user()->id_perusahaan,
            $request->validated('dari'),
            $request->validated('sampai'),
            $request->validated('tipe'),
            (string) $request->user()->kode_peran,
        );
        return ApiResponse::success($hasil);
    }

    public function exportLaporanExcel(LaporanPengadaanRequest $request): BinaryFileResponse
    {
        $dari = $request->validated('dari');
        $sampai = $request->validated('sampai');
        $laporan = $this->service->laporan((string) $request->user()->id_perusahaan, $dari, $sampai, $request->validated('tipe'), (string) $request->user()->kode_peran);

        return Excel::download(
            new LaporanPengadaanExport($laporan, $dari, $sampai),
            'laporan-pengadaan-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportLaporanPdf(LaporanPengadaanRequest $request): Response
    {
        $dari = $request->validated('dari');
        $sampai = $request->validated('sampai');
        $laporan = $this->service->laporan((string) $request->user()->id_perusahaan, $dari, $sampai, $request->validated('tipe'), (string) $request->user()->kode_peran);

        $pdf = Pdf::loadView('exports.laporan-pengadaan', [
            'laporan'    => $laporan,
            'dari'       => $dari,
            'sampai'     => $sampai,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan((string) $request->user()->id_perusahaan),
        ]);

        return $pdf->download('laporan-pengadaan-' . date('Ymd') . '.pdf');
    }

    private function logoBase64(): ?string
    {
        $path = public_path('img/logo/logo-sli.png');
        if (!is_file($path)) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }

    public function batal(BatalPermintaanPembelianRequest $request, string $id): JsonResponse
    {
        $record = $this->service->batal($id, (string) $request->validated()['alasan'], ...$this->aktor($request));
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Permintaan dibatalkan');
    }

    public function tambahBukti(UploadBuktiPermintaanPembelianRequest $request, string $id): JsonResponse
    {
        $record = $this->service->tambahBukti($id, $request->file('bukti'), (string) $request->validated()['tahap'], ...$this->aktor($request));
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Lampiran berhasil diunggah');
    }

    public function hapusBukti(Request $request, string $id, string $idBukti): JsonResponse
    {
        $record = $this->service->hapusBukti($id, $idBukti, ...$this->aktor($request));
        return ApiResponse::success(new PermintaanPembelianResource($record), 'Lampiran berhasil dihapus');
    }

    public function infoPengajuan(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->service->infoPengajuanKeuangan($id, (string) $request->user()->id_perusahaan));
    }

    /** @return array{0:string,1:string,2:string} */
    private function aktor(Request $request): array
    {
        $user = $request->user();
        return [(string) $user->id_perusahaan, (string) $user->id_pengguna, (string) $user->kode_peran];
    }
}
