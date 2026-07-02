<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Helpers\ApiResponse;
use App\Modules\EvaluasiTrip\Requests\StoreEvaluasiTripRequest;
use App\Modules\EvaluasiTrip\Requests\UpdateEvaluasiTripRequest;
use App\Modules\EvaluasiTrip\Resources\EvaluasiTripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class EvaluasiTripController extends Controller
{
    public function __construct(private readonly EvaluasiTripService $service) {}

    public function showByPenugasan(string $idPenugasan): JsonResponse
    {
        return ApiResponse::success(new EvaluasiTripResource($this->service->getByPenugasan($idPenugasan)));
    }

    public function storeByPenugasan(StoreEvaluasiTripRequest $request, string $idPenugasan): JsonResponse
    {
        $record = $this->service->create($idPenugasan, $request->validated());
        return ApiResponse::success(new EvaluasiTripResource($record), 'Evaluasi trip berhasil dibuat', 201);
    }

    public function update(UpdateEvaluasiTripRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new EvaluasiTripResource($record), 'Evaluasi trip berhasil diperbarui');
    }
}
