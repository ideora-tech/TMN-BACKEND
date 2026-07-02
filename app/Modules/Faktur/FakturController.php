<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Helpers\ApiResponse;
use App\Modules\Faktur\Requests\StoreFakturRequest;
use App\Modules\Faktur\Requests\UpdateFakturRequest;
use App\Modules\Faktur\Requests\UpdateStatusFakturRequest;
use App\Modules\Faktur\Resources\FakturResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class FakturController extends Controller
{
    public function __construct(private readonly FakturService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            FakturResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new FakturResource($this->service->findOrFail($id)));
    }

    public function store(StoreFakturRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new FakturResource($record), 'Faktur berhasil dibuat', 201);
    }

    public function update(UpdateFakturRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new FakturResource($record), 'Faktur berhasil diperbarui');
    }

    public function updateStatus(UpdateStatusFakturRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateStatus($id, $request->validated()['status']);
        return ApiResponse::success(new FakturResource($record), 'Status faktur berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Faktur berhasil dihapus');
    }
}
