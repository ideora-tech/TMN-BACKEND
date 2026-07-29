<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Helpers\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $service) {}

    public function pengaturan(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->pengaturan($idPerusahaan));
    }

    public function simpanPengaturan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tanggal_mulai_cutoff'       => ['required', 'integer', 'min:1', 'max:28'],
            'hari_kerja_per_bulan'       => ['required', 'integer', 'min:1', 'max:31'],
            'persen_bpjs_kesehatan'      => ['required', 'numeric', 'min:0', 'max:100'],
            'persen_bpjs_jht'            => ['required', 'numeric', 'min:0', 'max:100'],
            'persen_bpjs_jp'             => ['required', 'numeric', 'min:0', 'max:100'],
            'plafon_gaji_bpjs_kesehatan' => ['required', 'numeric', 'min:0'],
        ]);

        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->simpanPengaturan($idPerusahaan, $validated), 'Pengaturan payroll tersimpan');
    }

    public function previewRentang(Request $request): JsonResponse
    {
        $request->validate(['bulan' => ['required', 'date_format:Y-m']]);
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->rentangPeriode($idPerusahaan, (string) $request->get('bulan')));
    }

    public function indexPeriode(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listPeriode(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->get('status'),
        );

        return ApiResponse::paginated($result['data'], $result['meta']);
    }

    public function storePeriode(Request $request): JsonResponse
    {
        $request->validate(['bulan' => ['required', 'date_format:Y-m']]);
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $periode = $this->service->buatPeriode($idPerusahaan, (string) $request->get('bulan'));
        return ApiResponse::success($periode, 'Periode payroll berhasil dibuat', 201);
    }

    public function showPeriode(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->detailPeriode($id, $idPerusahaan));
    }

    public function destroyPeriode(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->hapusPeriode($id, $idPerusahaan);
        return ApiResponse::success(null, 'Periode payroll dihapus');
    }

    public function generate(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $hasil = $this->service->generateSlip($id, $idPerusahaan);
        return ApiResponse::success($hasil, "Slip gaji berhasil dibuat ({$hasil['dibuat']} karyawan)");
    }

    public function updateSlip(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'tunjangan_lain'       => ['sometimes', 'numeric', 'min:0'],
            'keterangan_tunjangan' => ['sometimes', 'nullable', 'string', 'max:255'],
            'potongan_lain'        => ['sometimes', 'numeric', 'min:0'],
            'keterangan_potongan'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'pph21'                => ['sometimes', 'numeric', 'min:0'],
            'catatan'              => ['sometimes', 'nullable', 'string'],
        ]);

        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $slip = $this->service->updateSlip($id, $idPerusahaan, $validated);
        return ApiResponse::success($slip, 'Slip berhasil diperbarui');
    }

    public function slipPdf(Request $request, string $id): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $this->service->slipUntukPdf($id, $idPerusahaan);
        $data['logoBase64'] = $this->logoBase64();

        $pdf = Pdf::loadView('exports.slip-gaji', $data);
        $namaFile = 'slip-gaji-' . ($data['slip']->karyawan_nik ?? 'karyawan') . '.pdf';

        return $pdf->download($namaFile);
    }

    private function logoBase64(): ?string
    {
        $path = public_path('img/logo/logo-sli.png');
        if (!is_file($path)) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }

    public function finalisasi(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $periode = $this->service->finalisasi($id, $idPerusahaan);
        return ApiResponse::success($periode, 'Periode payroll difinalisasi');
    }

    public function batalFinalisasi(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $periode = $this->service->batalFinalisasi($id, $idPerusahaan);
        return ApiResponse::success($periode, 'Finalisasi dibatalkan — periode kembali draft');
    }
}
