<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Helpers\ApiResponse;
use App\Modules\DokumenArmada\Requests\PerpanjangDokumenArmadaRequest;
use App\Modules\DokumenArmada\Requests\StoreDokumenArmadaBatchRequest;
use App\Modules\DokumenArmada\Requests\StoreDokumenArmadaRequest;
use App\Modules\DokumenArmada\Requests\UpdateDokumenArmadaRequest;
use App\Modules\DokumenArmada\Resources\DokumenArmadaResource;
use App\Modules\DokumenArmada\Resources\DokumenPerUnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class DokumenArmadaController extends Controller
{
    public function __construct(private readonly DokumenArmadaService $service) {}

    public function indexByArmada(Request $request, string $idArmada): JsonResponse
    {
        if ($request->boolean('dengan_riwayat')) {
            return ApiResponse::success(DokumenArmadaResource::collection(
                $this->service->listDenganRiwayat($idArmada, (string) $request->user()->id_perusahaan)
            ));
        }

        $result = $this->service->listByArmada(
            $idArmada,
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 100)
        );
        return ApiResponse::paginated(
            DokumenArmadaResource::collection($result['data']),
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
            $request->get('jenis_dokumen'),
            $request->get('search')
        );

        return ApiResponse::paginated(
            DokumenArmadaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function perUnit(Request $request): JsonResponse
    {
        $request->validate([
            'kondisi' => ['nullable', Rule::in(DokumenArmadaService::KONDISI_UNIT)],
        ], [
            'kondisi.in' => 'Filter kondisi tidak dikenal',
        ]);

        $result = $this->service->listPerUnit(
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_armada'),
            $request->get('jenis_dokumen'),
            $request->get('search'),
            $request->get('kondisi'),
        );

        return ApiResponse::paginated(
            DokumenPerUnitResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->detail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new DokumenArmadaResource($record));
    }

    public function store(StoreDokumenArmadaRequest $request, string $idArmada): JsonResponse
    {
        $record = $this->service->create(
            $idArmada,
            $request->validated(),
            $request->file('file'),
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(new DokumenArmadaResource($record), 'Dokumen armada berhasil dibuat', 201);
    }

    public function storeBatch(StoreDokumenArmadaBatchRequest $request, string $idArmada): JsonResponse
    {
        $records = $this->service->createBatch(
            $idArmada,
            $request->validated()['dokumen'],
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(
            DokumenArmadaResource::collection($records),
            count($records) . ' dokumen armada berhasil disimpan',
            201
        );
    }

    public function update(UpdateDokumenArmadaRequest $request, string $idArmada, string $id): JsonResponse
    {
        $record = $this->service->update(
            $idArmada,
            $id,
            $request->validated(),
            $request->file('file'),
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(new DokumenArmadaResource($record), 'Dokumen armada berhasil diperbarui');
    }

    public function perpanjang(PerpanjangDokumenArmadaRequest $request, string $idArmada, string $id): JsonResponse
    {
        $data = $request->validated();
        unset($data['file']);
        $record = $this->service->perpanjang(
            $idArmada,
            $id,
            $data,
            $request->file('file'),
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(new DokumenArmadaResource($record), 'Dokumen armada berhasil diperpanjang', 201);
    }

    public function destroy(Request $request, string $idArmada, string $id): JsonResponse
    {
        $this->service->delete($idArmada, $id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Dokumen armada berhasil dihapus');
    }

    public function expiring(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $days = (int) $request->get('days', 30);
        $records = $this->service->getExpiring($idPerusahaan, $days);
        return ApiResponse::success(DokumenArmadaResource::collection($records));
    }
}
