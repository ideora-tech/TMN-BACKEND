<?php

declare(strict_types=1);

namespace App\Modules\LaporanPemasukan;

use App\Helpers\ApiResponse;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\LaporanPemasukan\Exports\LaporanPemasukanExport;
use App\Modules\LaporanPemasukan\Requests\LaporanPemasukanRequest;
use App\Support\AsalFrontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LaporanPemasukanController extends Controller
{
    public function __construct(private readonly LaporanPemasukanService $service) {}

    public function index(LaporanPemasukanRequest $request): JsonResponse
    {
        $laporan = $this->service->laporan(
            (string) $request->user()->id_perusahaan,
            $request->filter(),
            $request->halaman(),
            $request->batas(),
        );

        return ApiResponse::success($laporan + ['opsi_kategori' => $this->service->opsiKategori()]);
    }

    public function exportExcel(LaporanPemasukanRequest $request, ArusKasService $arusKasService): BinaryFileResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        return Excel::download(
            new LaporanPemasukanExport(
                $this->service->untukExport($idPerusahaan, $request->filter()),
                $arusKasService->namaPerusahaan($idPerusahaan),
                AsalFrontend::dari($request),
            ),
            'laporan-pemasukan-' . date('Ymd') . '.xlsx',
        );
    }
}
