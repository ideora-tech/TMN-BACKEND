<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan;

use App\Helpers\ApiResponse;
use App\Modules\JadwalKeberangkatan\Requests\StoreJadwalKeberangkatanRequest;
use App\Modules\JadwalKeberangkatan\Requests\UpdateJadwalKeberangkatanRequest;
use App\Modules\JadwalKeberangkatan\Resources\JadwalKeberangkatanResource;
use App\Modules\Supir\SupirModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JadwalKeberangkatanController extends Controller
{
    public function __construct(private readonly JadwalKeberangkatanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'id_penugasan' => ['required', 'string', 'max:36'],
        ]);

        $result = $this->service->list(
            (string) $request->get('id_penugasan'),
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            JadwalKeberangkatanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new JadwalKeberangkatanResource($this->service->findOrFail($id)));
    }

    public function saya(Request $request): JsonResponse
    {
        $supir = SupirModel::active()
            ->where('id_pengguna', (string) $request->user()->id_pengguna)
            ->first();

        if (!$supir) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        $result = $this->service->listBySupir(
            (string) $supir->id_supir,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            JadwalKeberangkatanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function store(StoreJadwalKeberangkatanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(
            new JadwalKeberangkatanResource($record),
            'Jadwal keberangkatan berhasil dibuat',
            201
        );
    }

    public function update(UpdateJadwalKeberangkatanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(
            new JadwalKeberangkatanResource($record),
            'Jadwal keberangkatan berhasil diperbarui'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Jadwal keberangkatan berhasil dihapus');
    }
}
