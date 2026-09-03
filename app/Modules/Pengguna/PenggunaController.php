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
        $search = $request->get('search') !== null && $request->get('search') !== '' ? (string) $request->get('search') : null;
        $aktif  = $request->get('aktif') !== null && $request->get('aktif') !== '' ? (string) $request->get('aktif') : null;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $search,
            $aktif
        );

        return ApiResponse::paginated(
            PenggunaResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new PenggunaResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
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
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PenggunaResource($record), 'Pengguna berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
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
