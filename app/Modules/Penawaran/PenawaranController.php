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

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new PenawaranResource($this->service->findOrFail($id)));
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

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Penawaran berhasil dihapus');
    }

    public function updateStatus(UpdateStatusPenawaranRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateStatus($id, $request->validated()['status']);
        return ApiResponse::success(new PenawaranResource($record), 'Status penawaran berhasil diperbarui');
    }

    public function exportPdf(Request $request, string $id): Response
    {
        $penawaran = $this->service->findOrFail($id);

        if ($penawaran->id_perusahaan !== (string) $request->user()->id_perusahaan) {
            abort(404, 'Penawaran tidak ditemukan');
        }

        $klien = $penawaran->id_klien ? $this->klienRepo->findById($penawaran->id_klien) : null;

        $pdf = Pdf::loadView('exports.penawaran', ['p' => $penawaran, 'klien' => $klien]);

        return $pdf->download('penawaran-' . $penawaran->nomor_penawaran . '.pdf');
    }
}