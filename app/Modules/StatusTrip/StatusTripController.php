<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Helpers\ApiResponse;
use App\Modules\StatusTrip\Requests\StoreStatusTripRequest;
use App\Modules\StatusTrip\Resources\StatusTripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StatusTripController extends Controller
{
    public function __construct(private readonly StatusTripService $service) {}

    public function index(Request $request, string $idTrip): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $this->service->listByTrip($idTrip, $idPerusahaan);
        return ApiResponse::success(StatusTripResource::collection($data));
    }

    public function store(StoreStatusTripRequest $request, string $idTrip): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($idTrip, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new StatusTripResource($record), 'Status trip berhasil ditambahkan', 201);
    }
}
