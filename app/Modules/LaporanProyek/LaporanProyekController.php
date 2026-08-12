<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek;

use App\Helpers\ApiResponse;
use App\Modules\LaporanProyek\Exports\LaporanProyekExport;
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
            (int) $request->get('limit', 10),
            $request->get('search')
        );

        return ApiResponse::paginated(
            LaporanProyekResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->service->detail($id, (string) $request->user()->id_perusahaan));
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
        $items = $this->service->dataExport((string) $request->user()->id_perusahaan);

        return Excel::download(
            new LaporanProyekExport(collect($items)),
            'laporan-proyek-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportPdf(Request $request): Response
    {
        $items = $this->service->dataExport((string) $request->user()->id_perusahaan);

        $pdf = Pdf::loadView('exports.laporan', ['items' => $items]);

        return $pdf->download('laporan-proyek-' . date('Ymd') . '.pdf');
    }
}
