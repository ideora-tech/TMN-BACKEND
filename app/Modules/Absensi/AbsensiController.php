<?php

declare(strict_types=1);

namespace App\Modules\Absensi;

use App\Helpers\ApiResponse;
use App\Modules\Absensi\Exports\RekapAbsensiExport;
use App\Modules\Absensi\Requests\SimpanAbsensiHarianRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AbsensiController extends Controller
{
    private const MAKS_BARIS_EXPORT = 10000;

    public function __construct(private readonly AbsensiService $service) {}

    public function harian(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $tanggal = (string) $request->get('tanggal', now()->toDateString());

        return ApiResponse::success($this->service->harian($idPerusahaan, $tanggal));
    }

    public function simpanHarian(SimpanAbsensiHarianRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $validated = $request->validated();

        $hasil = $this->service->simpanHarian($idPerusahaan, $validated['tanggal'], $validated['entries']);

        return ApiResponse::success($hasil, "Absensi tersimpan ({$hasil['tersimpan']} karyawan)");
    }

    public function pengaturan(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->pengaturan($idPerusahaan));
    }

    public function simpanPengaturan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jam_masuk'  => ['required', 'date_format:H:i'],
            'jam_pulang' => ['required', 'date_format:H:i', 'after:jam_masuk'],
            'toleransi_terlambat_menit' => ['required', 'integer', 'min:0', 'max:120'],
        ]);

        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $hasil = $this->service->simpanPengaturan($idPerusahaan, $validated);

        return ApiResponse::success($hasil, 'Pengaturan jam kerja tersimpan');
    }

    public function absensiSaya(Request $request): JsonResponse
    {
        $user = $request->user();
        return ApiResponse::success($this->service->absensiSaya((string) $user->id_perusahaan, $user->id_karyawan));
    }

    public function absenMasuk(Request $request): JsonResponse
    {
        $user = $request->user();
        $lokasi = $request->validate([
            'latitude'    => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'   => ['nullable', 'numeric', 'between:-180,180'],
            'alamat'      => ['nullable', 'string', 'max:500'],
            'foto'        => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'skor_wajah'  => ['nullable', 'numeric', 'between:0,1'],
            'wajah_cocok' => ['nullable', 'boolean'],
        ]);
        return ApiResponse::success(
            $this->service->absenMasuk((string) $user->id_perusahaan, $user->id_karyawan, $lokasi, $request->file('foto')),
            'Absen masuk tercatat'
        );
    }

    public function absenPulang(Request $request): JsonResponse
    {
        $user = $request->user();
        $lokasi = $request->validate([
            'latitude'    => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'   => ['nullable', 'numeric', 'between:-180,180'],
            'alamat'      => ['nullable', 'string', 'max:500'],
            'foto'        => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'skor_wajah'  => ['nullable', 'numeric', 'between:0,1'],
            'wajah_cocok' => ['nullable', 'boolean'],
        ]);
        return ApiResponse::success(
            $this->service->absenPulang((string) $user->id_perusahaan, $user->id_karyawan, $lokasi, $request->file('foto')),
            'Absen pulang tercatat'
        );
    }

    public function rekap(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $bulan = (string) $request->get('bulan', now()->format('Y-m'));

        $result = $this->service->rekapBulanan(
            $idPerusahaan,
            $bulan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
        );

        return ApiResponse::paginated($result['data'], $result['meta']);
    }

    public function exportRekapExcel(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'bulan'  => ['nullable', 'date_format:Y-m'],
            'search' => ['nullable', 'string', 'max:100'],
        ], [
            'bulan.date_format' => 'Format bulan harus YYYY-MM',
        ]);

        $bulan = $validated['bulan'] ?? now()->format('Y-m');
        $result = $this->service->rekapBulanan(
            (string) $request->user()->id_perusahaan,
            $bulan,
            1,
            self::MAKS_BARIS_EXPORT,
            $validated['search'] ?? null,
        );

        return Excel::download(new RekapAbsensiExport(collect($result['data']), $bulan), "rekap-absensi-{$bulan}.xlsx");
    }
}
