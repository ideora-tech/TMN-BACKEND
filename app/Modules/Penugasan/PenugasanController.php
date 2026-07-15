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
        $page   = (int) $request->get('page', 1);
        $limit  = (int) $request->get('limit', 10);
        $sumber = $request->filled('sumber') ? (string) $request->get('sumber') : null;

        if ($request->filled('id_armada')) {
            $result = $this->service->listByArmada((string) $request->get('id_armada'), $page, $limit, $sumber);
        } elseif ($request->filled('id_supir')) {
            $result = $this->service->listBySupir((string) $request->get('id_supir'), $page, $limit, $sumber);
        } elseif ($request->filled('id_proyek')) {
            $result = $this->service->list((string) $request->get('id_proyek'), $page, $limit, $sumber);
        } else {
            abort(422, 'Parameter id_proyek, id_armada, atau id_supir wajib diisi');
        }

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
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($request->validated(), $idPerusahaan);
        return ApiResponse::success(new PenugasanResource($record), 'Penugasan berhasil dibuat', 201);
    }

    public function update(UpdatePenugasanRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new PenugasanResource($record), 'Penugasan berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Penugasan berhasil dihapus');
    }
}
