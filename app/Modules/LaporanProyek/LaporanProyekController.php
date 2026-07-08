<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek;

use App\Helpers\ApiResponse;
use App\Modules\LaporanProyek\Exports\LaporanProyekExport;
use App\Modules\LaporanProyek\LaporanProyekModel;
use App\Modules\LaporanProyek\Requests\StoreLaporanProyekRequest;
use App\Modules\LaporanProyek\Requests\UpdateLaporanProyekRequest;
use App\Modules\LaporanProyek\Resources\LaporanProyekResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LaporanProyekController extends Controller
{
    public function __construct(private readonly LaporanProyekService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            LaporanProyekResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new LaporanProyekResource($this->service->findOrFail($id)));
    }

    public function showByProyek(string $idProyek): JsonResponse
    {
        return ApiResponse::success(new LaporanProyekResource($this->service->getByProyek($idProyek)));
    }

    public function store(StoreLaporanProyekRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(new LaporanProyekResource($record), 'Laporan proyek berhasil dibuat', 201);
    }

    public function update(UpdateLaporanProyekRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new LaporanProyekResource($record), 'Laporan proyek berhasil diperbarui');
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;

        $items = LaporanProyekModel::active()
            ->join('proyek as pr', 'laporan_proyek.id_proyek', '=', 'pr.id_proyek')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->select('laporan_proyek.*')
            ->orderBy('laporan_proyek.dibuat_pada', 'DESC')
            ->get();

        return Excel::download(
            new LaporanProyekExport(collect($items)),
            'laporan-proyek-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportPdf(Request $request): Response
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;

        $items = LaporanProyekModel::active()
            ->join('proyek as pr', 'laporan_proyek.id_proyek', '=', 'pr.id_proyek')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->select('laporan_proyek.*')
            ->orderBy('laporan_proyek.dibuat_pada', 'DESC')
            ->get();

        $pdf = Pdf::loadView('exports.laporan', ['items' => $items]);

        return $pdf->download('laporan-proyek-' . date('Ymd') . '.pdf');
    }
}
