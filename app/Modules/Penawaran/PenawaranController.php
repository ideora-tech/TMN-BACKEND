<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Helpers\ApiResponse;
use App\Modules\Klien\Contracts\KlienRepositoryInterface;
use App\Modules\Penawaran\Requests\StorePenawaranRequest;
use App\Modules\Penawaran\Requests\UpdatePenawaranRequest;
use App\Modules\Penawaran\Requests\UpdateStatusPenawaranRequest;
use App\Modules\Penawaran\Resources\PenawaranResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PenawaranController extends Controller
{
    public function __construct(
        private readonly PenawaranService $service,
        private readonly KlienRepositoryInterface $klienRepo
    ) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->get('status'),
            $request->get('id_proyek')
        );

        return ApiResponse::paginated(
            PenawaranResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new PenawaranResource($this->service->detailDenganInfoProyek($id, $idPerusahaan)));
    }

    public function store(StorePenawaranRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new PenawaranResource($record), 'Penawaran berhasil dibuat', 201);
    }

    public function update(UpdatePenawaranRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record       = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new PenawaranResource($record), 'Penawaran berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Penawaran berhasil dihapus');
    }

    public function updateStatus(UpdateStatusPenawaranRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->updateStatus($id, $request->validated()['status'], $idPerusahaan);
        return ApiResponse::success(new PenawaranResource($record), 'Status penawaran berhasil diperbarui');
    }

    public function ajukanApproval(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->ajukanApproval($id, (string) $request->user()->id_pengguna, $idPerusahaan);
        return ApiResponse::success(new PenawaranResource($record), 'Penawaran diajukan untuk approval');
    }

    public function exportPdf(Request $request, string $id): Response
    {
        $penawaran = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);

        $klien = $penawaran->id_klien ? $this->klienRepo->findById($penawaran->id_klien) : null;

        $pdf = Pdf::loadView('exports.penawaran', [
            'p'          => $penawaran,
            'klien'      => $klien,
            'items'      => $penawaran->items,
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan((string) $request->user()->id_perusahaan),
        ]);

        return $pdf->download('penawaran-' . $penawaran->nomor_penawaran . '.pdf');
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