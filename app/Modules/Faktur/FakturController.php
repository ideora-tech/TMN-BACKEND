<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Helpers\ApiResponse;
use App\Modules\Faktur\Requests\StoreFakturRequest;
use App\Modules\Faktur\Requests\UpdateFakturRequest;
use App\Modules\Faktur\Requests\UpdateStatusFakturRequest;
use App\Modules\Faktur\Resources\FakturResource;
use App\Modules\Faktur\Exports\FakturExport;
use App\Modules\Faktur\FakturModel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FakturController extends Controller
{
    public function __construct(private readonly FakturService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            FakturResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new FakturResource($this->service->findOrFail($id)));
    }

    public function store(StoreFakturRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new FakturResource($record), 'Faktur berhasil dibuat', 201);
    }

    public function update(UpdateFakturRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new FakturResource($record), 'Faktur berhasil diperbarui');
    }

    public function updateStatus(UpdateStatusFakturRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateStatus($id, $request->validated()['status']);
        return ApiResponse::success(new FakturResource($record), 'Status faktur berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Faktur berhasil dihapus');
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $items = FakturModel::whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->with('klien')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('dibuat_pada', 'DESC')
            ->get();

        return Excel::download(
            new FakturExport(collect($items)),
            'faktur-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportPdf(Request $request): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $items = FakturModel::whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->with('klien')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('dibuat_pada', 'DESC')
            ->get();

        $pdf = Pdf::loadView('exports.faktur', ['items' => $items]);

        return $pdf->download('faktur-' . date('Ymd') . '.pdf');
    }
}
