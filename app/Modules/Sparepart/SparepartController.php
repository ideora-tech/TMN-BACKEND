<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Helpers\ApiResponse;
use App\Modules\Sparepart\Exports\SparepartTemplateExport;
use App\Modules\Sparepart\Requests\ImportSparepartRequest;
use App\Modules\Sparepart\Requests\StokSparepartRequest;
use App\Modules\Sparepart\Requests\StoreSparepartRequest;
use App\Modules\Sparepart\Requests\UpdateSparepartRequest;
use App\Modules\Sparepart\Resources\SparepartMutasiResource;
use App\Modules\Sparepart\Resources\SparepartResource;
use App\Modules\Sparepart\Resources\SparepartRiwayatHargaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SparepartController extends Controller
{
    public function __construct(private readonly SparepartService $service) {}

    public function downloadTemplate(): BinaryFileResponse
    {
        return Excel::download(new SparepartTemplateExport(), 'template-import-sparepart.xlsx');
    }

    public function import(ImportSparepartRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->import($request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'Import spare part selesai diproses');
    }

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->get('id_kategori_sparepart')
        );

        return ApiResponse::paginated(
            SparepartResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new SparepartResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StoreSparepartRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new SparepartResource($record), 'Spare part berhasil dibuat', 201);
    }

    public function update(UpdateSparepartRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new SparepartResource($record), 'Spare part berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Spare part berhasil dihapus');
    }

    public function mutasiStok(StokSparepartRequest $request, string $id): JsonResponse
    {
        $record = $this->service->mutasiStok($id, $request->validated());
        return ApiResponse::success(new SparepartResource($record), 'Stok berhasil diperbarui');
    }

    public function listMutasi(Request $request, string $id): JsonResponse
    {
        $result = $this->service->listMutasi(
            $id,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            SparepartMutasiResource::collection($result['data']),
            $result['meta']
        );
    }

    public function listRiwayatHarga(Request $request, string $id): JsonResponse
    {
        $result = $this->service->listRiwayatHarga(
            $id,
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 20)
        );

        return ApiResponse::paginated(
            SparepartRiwayatHargaResource::collection($result['data']),
            $result['meta']
        );
    }
}
