<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan;

use App\Helpers\ApiResponse;
use App\Modules\PaketLangganan\Requests\StorePaketLanggananRequest;
use App\Modules\PaketLangganan\Requests\UpdatePaketLanggananRequest;
use App\Modules\PaketLangganan\Resources\PaketLanggananResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaketLanggananController extends Controller
{
    public function __construct(private readonly PaketLanggananService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            PaketLanggananResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new PaketLanggananResource($this->service->findOrFail($id)));
    }

    public function store(StorePaketLanggananRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(new PaketLanggananResource($record), 'Paket langganan berhasil dibuat', 201);
    }

    public function update(UpdatePaketLanggananRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new PaketLanggananResource($record), 'Paket langganan berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Paket langganan berhasil dihapus');
    }
}
