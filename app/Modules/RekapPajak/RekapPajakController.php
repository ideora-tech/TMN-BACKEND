<?php

declare(strict_types=1);

namespace App\Modules\RekapPajak;

use App\Helpers\ApiResponse;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\RekapPajak\Exports\RekapPajakExport;
use App\Modules\RekapPajak\Requests\RekapPajakRequest;
use App\Support\AsalFrontend;
use App\Support\Exports\SheetTabelTautan;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RekapPajakController extends Controller
{
    public function __construct(private readonly RekapPajakService $service) {}

    public function index(RekapPajakRequest $request): JsonResponse
    {
        $rekap = $this->service->rekap(
            (string) $request->user()->id_perusahaan,
            $request->teks('dari'),
            $request->teks('sampai'),
        );
        unset($rekap['baris']);

        return ApiResponse::success($rekap);
    }

    public function rincian(RekapPajakRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->rincian(
            (string) $request->user()->id_perusahaan,
            $request->teks('jenis') ?? RekapPajakService::PPN_KELUARAN,
            $request->teks('dari'),
            $request->teks('sampai'),
            $request->teks('search'),
            $request->halaman(),
            $request->batas(),
        ));
    }

    public function exportExcel(RekapPajakRequest $request, ArusKasService $arusKasService): BinaryFileResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $rekap = $this->service->rekap($idPerusahaan, $request->teks('dari'), $request->teks('sampai'));

        SheetTabelTautan::pastikanBisaDiexport(array_sum(array_map('count', $rekap['baris'])));

        return Excel::download(
            new RekapPajakExport(
                $rekap,
                $arusKasService->namaPerusahaan($idPerusahaan),
                AsalFrontend::dari($request),
            ),
            'rekap-pajak-' . date('Ymd') . '.xlsx',
        );
    }
}
