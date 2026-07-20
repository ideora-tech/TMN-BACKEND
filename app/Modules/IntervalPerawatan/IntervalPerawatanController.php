<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan;

use App\Helpers\ApiResponse;
use App\Modules\IntervalPerawatan\Requests\StoreIntervalPerawatanRequest;
use App\Modules\IntervalPerawatan\Requests\UpdateIntervalPerawatanRequest;
use App\Modules\IntervalPerawatan\Resources\IntervalPerawatanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class IntervalPerawatanController extends Controller
{
    public function __construct(private readonly IntervalPerawatanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->query('page', 1),
            (int) $request->query('limit', 10),
            $request->query('id_jenis_perawatan'),
            $request->query('id_jenis_kendaraan'),
        );

        return ApiResponse::paginated(IntervalPerawatanResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findDetailOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new IntervalPerawatanResource($record));
    }

    public function store(StoreIntervalPerawatanRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan],
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new IntervalPerawatanResource($record), 'Interval perawatan berhasil ditambahkan', 201);
    }

    public function update(UpdateIntervalPerawatanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new IntervalPerawatanResource($record), 'Interval perawatan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Interval perawatan berhasil dihapus');
    }

    public function resolusi(Request $request): JsonResponse
    {
        $request->validate([
            'id_jenis_perawatan' => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['required', 'string', 'max:36'],
        ]);

        $intervalHari = $this->service->resolusi(
            (string) $request->user()->id_perusahaan,
            (string) $request->query('id_jenis_perawatan'),
            (string) $request->query('id_jenis_kendaraan'),
        );

        return ApiResponse::success($intervalHari !== null ? ['interval_hari' => $intervalHari] : null);
    }
}
