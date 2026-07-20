<?php

declare(strict_types=1);

namespace App\Modules\Armada;

use App\Helpers\ApiResponse;
use App\Modules\Armada\Exports\ArmadaTemplateExport;
use App\Modules\Armada\Requests\ImportArmadaRequest;
use App\Modules\Armada\Requests\StoreArmadaRequest;
use App\Modules\Armada\Requests\UpdateArmadaRequest;
use App\Modules\Armada\Resources\ArmadaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArmadaController extends Controller
{
    public function __construct(private readonly ArmadaService $service) {}

    public function downloadTemplate(): BinaryFileResponse
    {
        return Excel::download(new ArmadaTemplateExport(), 'template-import-armada.xlsx');
    }

    public function import(ImportArmadaRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->import($request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'Import armada selesai diproses');
    }

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $status = $request->get('status') !== null ? (string) $request->get('status') : null;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $status
        );

        return ApiResponse::paginated(
            ArmadaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new ArmadaResource($this->service->findOrFail($id)));
    }

    public function store(StoreArmadaRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data, $request->file('foto'));
        return ApiResponse::success(new ArmadaResource($record), 'Armada berhasil dibuat', 201);
    }

    public function update(UpdateArmadaRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan, $request->file('foto'));
        return ApiResponse::success(new ArmadaResource($record), 'Armada berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Armada berhasil dihapus');
    }

    public function servisJatuhTempo(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $days = (int) $request->get('days', 30);

        return ApiResponse::success($this->service->servisJatuhTempo($idPerusahaan, $days));
    }
}
