<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Helpers\ApiResponse;
use App\Modules\PerawatanArmada\Exports\PerawatanUnitExport;
use App\Modules\PerawatanArmada\Exports\RekapPerawatanUnitExport;
use App\Modules\PerawatanArmada\Requests\StorePerawatanArmadaRequest;
use App\Modules\PerawatanArmada\Requests\UpdatePerawatanArmadaRequest;
use App\Modules\PerawatanArmada\Requests\UploadBuktiPerawatanRequest;
use App\Modules\PerawatanArmada\Resources\PerawatanArmadaResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PerawatanArmadaController extends Controller
{
    public function __construct(private readonly PerawatanArmadaService $service) {}

    public function indexByArmada(Request $request, string $idArmada): JsonResponse
    {
        $result = $this->service->listByArmada(
            $idArmada,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            PerawatanArmadaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listByPerusahaan(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_armada'),
            $request->get('status'),
            $request->boolean('jatuh_tempo'),
            $request->get('search'),
            $request->get('tanggal_dari'),
            $request->get('tanggal_sampai'),
        );

        return ApiResponse::paginated(
            PerawatanArmadaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $idArmada, string $id): JsonResponse
    {
        return ApiResponse::success(new PerawatanArmadaResource($this->service->findOrFail($id)));
    }

    public function infoPengajuan(string $idArmada, string $id): JsonResponse
    {
        return ApiResponse::success($this->service->infoPengajuan($id));
    }

    public function store(StorePerawatanArmadaRequest $request, string $idArmada): JsonResponse
    {
        $record = $this->service->create($idArmada, $request->validated());
        return ApiResponse::success(new PerawatanArmadaResource($record), 'Perawatan armada berhasil dibuat', 201);
    }

    public function update(UpdatePerawatanArmadaRequest $request, string $idArmada, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new PerawatanArmadaResource($record), 'Perawatan armada berhasil diperbarui');
    }

    public function destroy(Request $request, string $idArmada, string $id): JsonResponse
    {
        $validated = $request->validate(
            ['alasan' => ['required', 'string', 'max:500']],
            ['alasan.required' => 'Alasan penghapusan wajib diisi'],
        );

        $this->service->delete($id, $validated['alasan']);
        return ApiResponse::success(null, 'Perawatan armada berhasil dihapus');
    }

    public function batal(Request $request, string $idArmada, string $id): JsonResponse
    {
        $validated = $request->validate(
            ['alasan' => ['required', 'string', 'max:500']],
            ['alasan.required' => 'Alasan pembatalan wajib diisi'],
        );

        $record = $this->service->batal($id, $validated['alasan']);
        return ApiResponse::success(new PerawatanArmadaResource($record), 'Perawatan armada dibatalkan');
    }

    public function storeBukti(UploadBuktiPerawatanRequest $request, string $idArmada, string $id): JsonResponse
    {
        $record = $this->service->tambahBukti($id, $request->file('bukti', []));
        return ApiResponse::success(new PerawatanArmadaResource($record), 'Bukti perawatan berhasil diunggah');
    }

    public function destroyBukti(string $idArmada, string $id, string $idBukti): JsonResponse
    {
        $this->service->hapusBukti($id, $idBukti);
        return ApiResponse::success(null, 'Bukti perawatan berhasil dihapus');
    }

    public function rekapPerUnit(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->rekapPerUnit(
            (string) $request->user()->id_perusahaan,
            $request->get('tanggal_dari'),
            $request->get('tanggal_sampai'),
        ));
    }

    public function exportUnitExcel(Request $request, string $idArmada): BinaryFileResponse
    {
        $dari = $request->get('tanggal_dari');
        $sampai = $request->get('tanggal_sampai');
        $data = $this->service->dataExportUnit($idArmada, (string) $request->user()->id_perusahaan, $dari, $sampai);

        return Excel::download(
            new PerawatanUnitExport(collect($data['items']), $data['armada'], $dari, $sampai),
            'perawatan-' . str_replace(' ', '', (string) $data['armada']->nopol) . '-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportUnitPdf(Request $request, string $idArmada): Response
    {
        $dari = $request->get('tanggal_dari');
        $sampai = $request->get('tanggal_sampai');
        $data = $this->service->dataExportUnit($idArmada, (string) $request->user()->id_perusahaan, $dari, $sampai);

        $pdf = Pdf::loadView('exports.perawatan-unit', [
            'armada'     => $data['armada'],
            'items'      => $data['items'],
            'dari'       => $dari,
            'sampai'     => $sampai,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan((string) $request->user()->id_perusahaan),
        ]);

        return $pdf->download('perawatan-' . str_replace(' ', '', (string) $data['armada']->nopol) . '-' . date('Ymd') . '.pdf');
    }

    public function exportRekapExcel(Request $request): BinaryFileResponse
    {
        $dari = $request->get('tanggal_dari');
        $sampai = $request->get('tanggal_sampai');
        $items = $this->service->rekapPerUnit((string) $request->user()->id_perusahaan, $dari, $sampai);

        return Excel::download(
            new RekapPerawatanUnitExport(collect($items), $dari, $sampai),
            'rekap-perawatan-unit-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportRekapPdf(Request $request): Response
    {
        $dari = $request->get('tanggal_dari');
        $sampai = $request->get('tanggal_sampai');
        $items = $this->service->rekapPerUnit((string) $request->user()->id_perusahaan, $dari, $sampai);

        $pdf = Pdf::loadView('exports.rekap-perawatan-unit', [
            'items'      => $items,
            'dari'       => $dari,
            'sampai'     => $sampai,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan((string) $request->user()->id_perusahaan),
        ]);

        return $pdf->download('rekap-perawatan-unit-' . date('Ymd') . '.pdf');
    }

    private function logoBase64(): ?string
    {
        $path = public_path('img/logo/logo-sli.png');
        if (!is_file($path)) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }

    public function prediksiPerawatan(Request $request, string $idArmada): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $days = (int) $request->get('days', 30);

        return ApiResponse::success($this->service->prediksiPerawatan($idArmada, $idPerusahaan, $days));
    }
}
