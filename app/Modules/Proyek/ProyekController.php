<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Helpers\ApiResponse;
use App\Modules\Faktur\Resources\FakturResource;
use App\Modules\Klien\Contracts\KlienRepositoryInterface;
use App\Modules\Penawaran\Resources\PenawaranResource;
use App\Modules\Proyek\Requests\StoreFakturBoronganRequest;
use App\Modules\Proyek\Requests\StorePenawaranRevisiRequest;
use App\Modules\Proyek\Requests\StoreProyekRequest;
use App\Modules\Proyek\Requests\UpdateProyekRequest;
use App\Modules\Proyek\Requests\UpdateStatusProyekRequest;
use App\Modules\Proyek\Resources\ProyekResource;
use App\Modules\ProyekRute\ProyekRuteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class ProyekController extends Controller
{
    public function __construct(
        private readonly ProyekService $service,
        private readonly KlienRepositoryInterface $klienRepo,
        private readonly ProyekRuteService $ruteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page   = (int) $request->get('page', 1);
        $limit  = (int) $request->get('limit', 10);
        $search = $request->get('search');
        $status = $request->get('status');

        $idPerusahaan = (string) $request->user()->id_perusahaan;

        if ($request->filled('id_klien')) {
            $result = $this->service->listByKlien((string) $request->get('id_klien'), $idPerusahaan, $page, $limit, $search, $status);
        } else {
            $result = $this->service->list($idPerusahaan, $page, $limit, $search, $status);
        }

        return ApiResponse::paginated(
            ProyekResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        $proyek = $this->service->findOrFail($id);

        $klien = $proyek->id_klien ? $this->klienRepo->findById((string) $proyek->id_klien) : null;
        $proyek->nama_klien = $klien->nama_klien ?? null;
        $proyek->realisasi  = $this->service->ringkasanRealisasi($proyek);

        return ApiResponse::success(new ProyekResource($proyek));
    }

    public function store(StoreProyekRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new ProyekResource($record), 'Proyek berhasil dibuat', 201);
    }

    public function update(UpdateProyekRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new ProyekResource($record), 'Proyek berhasil diperbarui');
    }

    public function updateStatus(UpdateStatusProyekRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateStatus($id, $request->validated()['status']);
        return ApiResponse::success(new ProyekResource($record), 'Status proyek berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Proyek berhasil dihapus');
    }

    public function buatPenawaranRevisi(StorePenawaranRevisiRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->buatPenawaranRevisi($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new PenawaranResource($record), 'Penawaran revisi berhasil dibuat', 201);
    }

    public function buatFakturBorongan(StoreFakturBoronganRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->buatFakturBorongan($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new FakturResource($record), 'Faktur berhasil dibuat', 201);
    }

    public function exportPdf(Request $request, string $id): Response
    {
        $proyek = $this->service->findOrFail($id);

        if ($proyek->id_perusahaan !== (string) $request->user()->id_perusahaan) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $klien = $proyek->id_klien ? $this->klienRepo->findById($proyek->id_klien) : null;

        $pdf = Pdf::loadView('exports.proyek', [
            'p'          => $proyek,
            'klien'      => $klien,
            'items'      => $this->ruteService->listByProyek($id),
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan((string) $request->user()->id_perusahaan),
        ]);

        return $pdf->download('proyek-' . $proyek->kode_proyek . '.pdf');
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
