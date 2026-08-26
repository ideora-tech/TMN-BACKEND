<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Helpers\ApiResponse;
use App\Modules\EvaluasiTrip\Requests\StoreEvaluasiTripRequest;
use App\Modules\EvaluasiTrip\Requests\UpdateEvaluasiTripRequest;
use App\Modules\EvaluasiTrip\Resources\EvaluasiTripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EvaluasiTripController extends Controller
{
    public function __construct(private readonly EvaluasiTripService $service) {}

    public function showByPenugasan(Request $request, string $idPenugasan): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new EvaluasiTripResource($this->service->getByPenugasan($idPenugasan, $idPerusahaan)));
    }

    public function storeByPenugasan(StoreEvaluasiTripRequest $request, string $idPenugasan): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($idPenugasan, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new EvaluasiTripResource($record), 'Evaluasi trip berhasil dibuat', 201);
    }

    public function update(UpdateEvaluasiTripRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new EvaluasiTripResource($record), 'Evaluasi trip berhasil diperbarui');
    }

    public function rekapVendor(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->rekapVendor($idPerusahaan));
    }

    public function penugasanUntukEvaluasi(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->listPenugasanUntukEvaluasi(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->filled('search') ? (string) $request->get('search') : null,
        );

        return ApiResponse::paginated($result['data'], $result['meta']);
    }

    public function listByVendor(Request $request, string $idVendor): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success($this->service->listEvaluasiVendor($idVendor, $idPerusahaan));
    }
}
