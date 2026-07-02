<?php

declare(strict_types=1);

namespace App\Modules\BriefingSupir;

use App\Helpers\ApiResponse;
use App\Modules\BriefingSupir\Requests\StoreBriefingSupirRequest;
use App\Modules\BriefingSupir\Requests\UpdateBriefingSupirRequest;
use App\Modules\BriefingSupir\Resources\BriefingSupirResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BriefingSupirController extends Controller
{
    public function __construct(private readonly BriefingSupirService $service) {}

    public function index(Request $request, string $idPenugasan): JsonResponse
    {
        $result = $this->service->list(
            $idPenugasan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            BriefingSupirResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new BriefingSupirResource($this->service->findOrFail($id)));
    }

    public function store(StoreBriefingSupirRequest $request, string $idPenugasan): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            [
                'id_penugasan'       => $idPenugasan,
                'id_dibriefing_oleh' => (string) $request->user()->id_pengguna,
                'waktu_briefing'     => now()->toDateTimeString(),
            ]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new BriefingSupirResource($record), 'Briefing supir berhasil dibuat', 201);
    }

    public function update(UpdateBriefingSupirRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new BriefingSupirResource($record), 'Briefing supir berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Briefing supir berhasil dihapus');
    }
}
