<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Helpers\ApiResponse;
use App\Modules\Faktur\Requests\StoreFakturRequest;
use App\Modules\Faktur\Requests\UpdateFakturRequest;
use App\Modules\Faktur\Requests\UpdateStatusFakturRequest;
use App\Modules\Faktur\Resources\FakturResource;
use App\Modules\Faktur\Exports\FakturDetailExport;
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
        $page   = (int) $request->get('page', 1);
        $limit  = (int) $request->get('limit', 10);
        $search = $request->filled('search') ? (string) $request->get('search') : null;
        $status = $request->filled('status') ? (string) $request->get('status') : null;

        if ($request->filled('id_klien')) {
            $result = $this->service->listByKlien((string) $request->get('id_klien'), $page, $limit);
        } else {
            $idPerusahaan = (string) $request->user()->id_perusahaan;
            $result = $this->service->list($idPerusahaan, $page, $limit, $search, $status);
        }

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

    public function exportExcel(Request $request, string $id): BinaryFileResponse
    {
        $faktur = $this->service->untukCetak($id, (string) $request->user()->id_perusahaan);

        return Excel::download(
            new FakturDetailExport($faktur),
            'faktur-' . $faktur->nomor_faktur . '.xlsx'
        );
    }

    public function exportPdf(Request $request, string $id): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $faktur       = $this->service->untukCetak($id, $idPerusahaan);

        $pdf = Pdf::loadView('exports.faktur', [
            'f'          => $faktur,
            'items'      => $faktur->items,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan($idPerusahaan),
        ]);

        return $pdf->download('faktur-' . $faktur->nomor_faktur . '.pdf');
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
