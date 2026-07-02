<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Helpers\ApiResponse;
use App\Modules\StatusTrip\Requests\StoreStatusTripRequest;
use App\Modules\StatusTrip\Resources\StatusTripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class StatusTripController extends Controller
{
    public function __construct(private readonly StatusTripService $service) {}

    public function index(string $idTrip): JsonResponse
    {
        $data = $this->service->listByTrip($idTrip);
        return ApiResponse::success(StatusTripResource::collection($data));
    }

    public function store(StoreStatusTripRequest $request, string $idTrip): JsonResponse
    {
        $record = $this->service->create($idTrip, $request->validated());
        return ApiResponse::success(new StatusTripResource($record), 'Status trip berhasil ditambahkan', 201);
    }
}
