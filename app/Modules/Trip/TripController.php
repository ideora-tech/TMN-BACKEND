<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Helpers\ApiResponse;
use App\Modules\Trip\Requests\StoreTripRequest;
use App\Modules\Trip\Resources\TripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TripController extends Controller
{
    public function __construct(private readonly TripService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_jadwal')
        );

        return ApiResponse::paginated(
            TripResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new TripResource($this->service->findOrFail($id)));
    }

    public function store(StoreTripRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(new TripResource($record), 'Trip berhasil dibuat', 201);
    }

    public function checkin(string $id): JsonResponse
    {
        $record = $this->service->checkin($id);
        return ApiResponse::success(new TripResource($record), 'Checkin berhasil');
    }

    public function checkout(string $id): JsonResponse
    {
        $record = $this->service->checkout($id);
        return ApiResponse::success(new TripResource($record), 'Checkout berhasil');
    }

    public function batalkan(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->batalkan($id, $idPerusahaan);
        return ApiResponse::success(new TripResource($record), 'Trip berhasil dibatalkan');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Trip berhasil dihapus');
    }

    public function rekapBiaya(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $this->service->rekapBiaya($id, $idPerusahaan);
        return ApiResponse::success($data, 'Rekap biaya berhasil dimuat');
    }
}
