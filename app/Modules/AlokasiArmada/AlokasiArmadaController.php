<?php

declare(strict_types=1);

namespace App\Modules\AlokasiArmada;

use App\Helpers\ApiResponse;
use App\Modules\AlokasiArmada\Exports\RiwayatArmadaExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AlokasiArmadaController extends Controller
{
    public function __construct(private readonly AlokasiArmadaService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('tanggal_dari'),
            $request->get('tanggal_sampai'),
            $request->get('search'),
            $request->get('id_armada'),
            $request->get('id_proyek'),
        );

        return ApiResponse::paginated(collect($result['data']), $result['meta']);
    }

    public function riwayat(Request $request): JsonResponse
    {
        $validated = $request->validate(['id_armada' => ['required', 'string']]);

        $data = $this->service->dataLaporanArmada(
            $validated['id_armada'],
            (string) $request->user()->id_perusahaan,
            $request->get('tanggal_dari'),
            $request->get('tanggal_sampai'),
        );

        return ApiResponse::success([
            'armada' => ['id_armada' => $data['armada']->id_armada, 'nopol' => $data['armada']->nopol, 'merk' => $data['armada']->merk ?? null],
            'items'  => $data['items'],
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $validated = $request->validate(['id_armada' => ['required', 'string']]);
        $dari = $request->get('tanggal_dari');
        $sampai = $request->get('tanggal_sampai');
        $data = $this->service->dataLaporanArmada($validated['id_armada'], (string) $request->user()->id_perusahaan, $dari, $sampai);

        return Excel::download(
            new RiwayatArmadaExport(collect($data['items']), $data['armada'], $dari, $sampai),
            'riwayat-armada-' . str_replace(' ', '', (string) $data['armada']->nopol) . '-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportPdf(Request $request): Response
    {
        $validated = $request->validate(['id_armada' => ['required', 'string']]);
        $dari = $request->get('tanggal_dari');
        $sampai = $request->get('tanggal_sampai');
        $data = $this->service->dataLaporanArmada($validated['id_armada'], (string) $request->user()->id_perusahaan, $dari, $sampai);

        $pdf = Pdf::loadView('exports.riwayat-armada', [
            'armada'     => $data['armada'],
            'items'      => $data['items'],
            'dari'       => $dari,
            'sampai'     => $sampai,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan((string) $request->user()->id_perusahaan),
        ]);

        return $pdf->download('riwayat-armada-' . str_replace(' ', '', (string) $data['armada']->nopol) . '-' . date('Ymd') . '.pdf');
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
