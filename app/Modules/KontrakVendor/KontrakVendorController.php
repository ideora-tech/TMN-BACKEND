<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Helpers\ApiResponse;
use App\Modules\KontrakVendor\Requests\ParseExcelKontrakVendorRequest;
use App\Modules\KontrakVendor\Requests\StoreKontrakVendorRequest;
use App\Modules\KontrakVendor\Requests\UpdateKontrakVendorRequest;
use App\Modules\KontrakVendor\Resources\KontrakVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class KontrakVendorController extends Controller
{
    public function __construct(private readonly KontrakVendorService $service) {}

    /**
     * List all kontrak vendor for the authenticated perusahaan.
     * GET /api/kontrak-vendor
     */
    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_vendor'),
            $request->get('search')
        );

        return ApiResponse::paginated(
            KontrakVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    /**
     * List kontrak vendor scoped to a specific proyek.
     * GET /api/proyek/{idProyek}/kontrak
     */
    public function indexByProyek(Request $request, string $idProyek): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listByProyek(
            $idPerusahaan,
            $idProyek,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            KontrakVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function templatePasangan(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Modules\KontrakVendor\Exports\PasanganUnitDriverTemplateExport(),
            'template-import-pasangan-unit-driver.xlsx',
        );
    }

    public function parsePasangan(ParseExcelKontrakVendorRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->parsePasangan($request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'File pasangan unit + driver selesai diproses');
    }

    public function ajukanApproval(Request $request, string $id): JsonResponse
    {
        $record = $this->service->ajukanApproval(
            $id,
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success(new KontrakVendorResource($record), 'Kontrak diajukan untuk approval');
    }

    public function timpaPasangan(ParseExcelKontrakVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->timpaPasangan($id, $request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'Pasangan unit + driver kontrak diperbarui dari excel');
    }

    public function timpaUnit(ParseExcelKontrakVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->timpaUnit($id, $request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'Daftar unit kontrak diperbarui dari excel');
    }

    public function timpaSupir(ParseExcelKontrakVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->timpaSupir($id, $request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'Daftar supir kontrak diperbarui dari excel');
    }

    public function parseUnit(ParseExcelKontrakVendorRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->parseUnit($request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'File unit selesai diproses');
    }

    public function parseSupir(ParseExcelKontrakVendorRequest $request): JsonResponse
    {
        $result = $this->service->parseSupir($request->file('file'));

        return ApiResponse::success($result, 'File supir selesai diproses');
    }

    public function exportPdf(Request $request, string $id): Response
    {
        $data = $this->service->dataCetak($id, (string) $request->user()->id_perusahaan);

        $pdf = Pdf::loadView('exports.kontrak-vendor', $data + ['logoBase64' => $this->logoBase64()]);

        return $pdf->download('kontrak-' . $this->namaFileAman($data['kontrak']->nomor_kontrak) . '.pdf');
    }

    private function namaFileAman(?string $nomor): string
    {
        $bersih = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $nomor);
        return trim((string) $bersih, '-') ?: 'vendor';
    }

    private function logoBase64(): ?string
    {
        $path = public_path('img/logo/logo-sli.png');
        if (!is_file($path)) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new KontrakVendorResource($this->service->findOrFail($id, $idPerusahaan)));
    }

    /**
     * Create kontrak vendor (scoped to a proyek via URL or body).
     * POST /api/proyek/{idProyek}/kontrak
     */
    public function storeForProyek(StoreKontrakVendorRequest $request, string $idProyek): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            [
                'id_perusahaan' => (string) $request->user()->id_perusahaan,
                'id_proyek'     => $idProyek,
            ]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KontrakVendorResource($record), 'Kontrak vendor berhasil dibuat', 201);
    }

    /**
     * Create kontrak vendor without a proyek (standalone).
     * POST /api/kontrak-vendor
     */
    public function store(StoreKontrakVendorRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KontrakVendorResource($record), 'Kontrak vendor berhasil dibuat', 201);
    }

    public function update(UpdateKontrakVendorRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new KontrakVendorResource($record), 'Kontrak vendor berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Kontrak vendor berhasil dihapus');
    }
}
