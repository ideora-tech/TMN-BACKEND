<?php

declare(strict_types=1);

namespace App\Modules\Absensi;

use App\Helpers\ApiResponse;
use App\Modules\Absensi\Requests\SimpanAbsensiHarianRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AbsensiController extends Controller
{
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
}
