<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Helpers\ApiResponse;
use App\Modules\Penugasan\Requests\StorePenugasanRequest;
use App\Modules\Penugasan\Requests\UpdatePenugasanRequest;
use App\Modules\Penugasan\Resources\PenugasanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PenugasanController extends Controller
{
    public function __construct(private readonly PenugasanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idProyek = (string) $request->get('id_proyek');

        if (empty($idProyek)) {
            abort(422, 'Parameter id_proyek wajib diisi');
        }

        $result = $this->service->list(
            $idProyek,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            PenugasanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new PenugasanResource($this->service->findOrFail($id)));
    }

    public function store(StorePenugasanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(new PenugasanResource($record), 'Penugasan berhasil dibuat', 201);
    }

    public function update(UpdatePenugasanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new PenugasanResource($record), 'Penugasan berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Penugasan berhasil dihapus');
    }
}
