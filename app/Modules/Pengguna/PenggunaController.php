<?php

declare(strict_types=1);

namespace App\Modules\Pengguna;

use App\Helpers\ApiResponse;
use App\Modules\Pengguna\Requests\ChangePasswordRequest;
use App\Modules\Pengguna\Requests\StorePenggunaRequest;
use App\Modules\Pengguna\Requests\UpdatePenggunaRequest;
use App\Modules\Pengguna\Resources\PenggunaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PenggunaController extends Controller
{
    public function __construct(private readonly PenggunaService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            PenggunaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new PenggunaResource($this->service->findOrFail($id)));
    }

    public function store(StorePenggunaRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new PenggunaResource($record), 'Pengguna berhasil dibuat', 201);
    }

    public function update(UpdatePenggunaRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new PenggunaResource($record), 'Pengguna berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Pengguna berhasil dihapus');
    }

    public function changePassword(ChangePasswordRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $this->service->changePassword(
            $id,
            $validated['password_lama'],
            $validated['password_baru']
        );
        return ApiResponse::success(null, 'Password berhasil diubah');
    }
}
