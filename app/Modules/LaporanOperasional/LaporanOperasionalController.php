<?php

declare(strict_types=1);

namespace App\Modules\LaporanOperasional;

use App\Helpers\ApiResponse;
use App\Modules\LaporanOperasional\Exports\ArmadaExport;
use App\Modules\LaporanOperasional\Exports\KaryawanExport;
use App\Modules\LaporanOperasional\Exports\LaporanTripExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LaporanOperasionalController extends Controller
{
    public function __construct(private readonly LaporanOperasionalService $service) {}

    private function filters(Request $request): array
    {
        return [
            'dari'      => $request->query('dari'),
            'sampai'    => $request->query('sampai'),
            'id_klien'  => $request->query('id_klien'),
            'id_supir'  => $request->query('id_supir'),
            'id_armada' => $request->query('id_armada'),
            'sumber'    => $request->query('sumber'),
        ];
    }

    public function indexTrip(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $page  = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 10);

        $result = $this->service->listTrip($idPerusahaan, $this->filters($request), $page, $limit);

        return ApiResponse::paginated($result['data'], $result['meta']);
    }

    public function ringkasanTrip(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $ringkasan = $this->service->ringkasanTrip($idPerusahaan, $this->filters($request));

        return ApiResponse::success($ringkasan);
    }

    public function exportTripExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $items = $this->service->exportTrip($idPerusahaan, $this->filters($request));

        return Excel::download(
            new LaporanTripExport($items),
            'laporan-trip-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportTripPdf(Request $request): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $items = $this->service->exportTrip($idPerusahaan, $this->filters($request));

        $pdf = Pdf::loadView('exports.laporan-trip', ['items' => $items]);

        return $pdf->download('laporan-trip-' . date('Ymd') . '.pdf');
    }

    public function exportKaryawanExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $items = $this->service->karyawanAktif($idPerusahaan);

        return Excel::download(
            new KaryawanExport(collect($items)),
            'karyawan-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportKaryawanPdf(Request $request): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $items = $this->service->karyawanAktif($idPerusahaan);

        $pdf = Pdf::loadView('exports.laporan-karyawan', ['items' => $items]);

        return $pdf->download('karyawan-' . date('Ymd') . '.pdf');
    }

    public function exportArmadaExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $items = $this->service->armadaAktif($idPerusahaan);

        return Excel::download(
            new ArmadaExport(collect($items)),
            'armada-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportArmadaPdf(Request $request): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $items = $this->service->armadaAktif($idPerusahaan);

        $pdf = Pdf::loadView('exports.laporan-armada', ['items' => $items]);

        return $pdf->download('armada-' . date('Ymd') . '.pdf');
    }
}
