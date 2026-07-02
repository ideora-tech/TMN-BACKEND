<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran;

use App\Helpers\ApiResponse;
use App\Modules\IzinPeran\Requests\BulkSetIzinPeranRequest;
use App\Modules\IzinPeran\Requests\UpdateIzinPeranRequest;
use App\Modules\IzinPeran\Resources\IzinPeranResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class IzinPeranController extends Controller
{
    public function __construct(private readonly IzinPeranService $service) {}

    /**
     * GET /api/v1/izin-peran?kode_peran=xxx
     * List all permissions for a given role within the authenticated user's company.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['kode_peran' => ['required', 'string', 'max:50']]);

        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $kodePeran    = (string) $request->get('kode_peran');

        $records = $this->service->listByPeran($idPerusahaan, $kodePeran);

        return ApiResponse::success(IzinPeranResource::collection($records));
    }

    /**
     * POST /api/v1/izin-peran/bulk
     * Bulk set (upsert) permissions for a role.
     */
    public function bulk(BulkSetIzinPeranRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $validated    = $request->validated();

        $this->service->bulkSet(
            $idPerusahaan,
            $validated['kode_peran'],
            $validated['permissions']
        );

        return ApiResponse::success(null, 'Izin peran berhasil diperbarui');
    }

    /**
     * PUT /api/v1/izin-peran/{id}
     * Update a single permission record.
     */
    public function update(UpdateIzinPeranRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new IzinPeranResource($record), 'Izin peran berhasil diperbarui');
    }
}
