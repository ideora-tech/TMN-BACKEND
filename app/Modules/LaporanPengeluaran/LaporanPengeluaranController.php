<?php

declare(strict_types=1);

namespace App\Modules\LaporanPengeluaran;

use App\Helpers\ApiResponse;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\LaporanPengeluaran\Exports\LaporanPengeluaranExport;
use App\Modules\LaporanPengeluaran\Requests\LaporanPengeluaranRequest;
use App\Support\AsalFrontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LaporanPengeluaranController extends Controller
{
    public function __construct(private readonly LaporanPengeluaranService $service) {}

    public function index(LaporanPengeluaranRequest $request): JsonResponse
    {
        $laporan = $this->service->laporan(
            (string) $request->user()->id_perusahaan,
            $request->filter(),
            $request->halaman(),
            $request->batas(),
        );

        return ApiResponse::success($laporan + ['opsi_kategori' => $this->service->opsiKategori()]);
    }

    public function exportExcel(LaporanPengeluaranRequest $request, ArusKasService $arusKasService): BinaryFileResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        return Excel::download(
            new LaporanPengeluaranExport(
                $this->service->untukExport($idPerusahaan, $request->filter()),
                $arusKasService->namaPerusahaan($idPerusahaan),
                AsalFrontend::dari($request),
            ),
            'laporan-pengeluaran-' . date('Ymd') . '.xlsx',
        );
    }
}
