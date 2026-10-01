<?php

declare(strict_types=1);

namespace App\Modules\TipePermintaan;

use App\Helpers\ApiResponse;
use App\Modules\TipePermintaan\Requests\StoreTipePermintaanRequest;
use App\Modules\TipePermintaan\Requests\UpdateTipePermintaanRequest;
use App\Modules\TipePermintaan\Resources\TipePermintaanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TipePermintaanController extends Controller
{
    public function __construct(private readonly TipePermintaanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $aktifRaw = $request->get('aktif');
        $aktif = ($aktifRaw === null || $aktifRaw === '') ? null : (bool) ((int) $aktifRaw);

        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $aktif
        );

        return ApiResponse::paginated(
            TipePermintaanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function opsiAktif(Request $request): JsonResponse
    {
        return ApiResponse::success(TipePermintaanResource::collection($this->service->listAktif((string) $request->user()->id_perusahaan)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new TipePermintaanResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StoreTipePermintaanRequest $request): JsonResponse
    {
        $record = $this->service->create(array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        ));
        return ApiResponse::success(new TipePermintaanResource($record), 'Tipe permintaan berhasil dibuat', 201);
    }

    public function update(UpdateTipePermintaanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new TipePermintaanResource($record), 'Tipe permintaan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Tipe permintaan berhasil dihapus');
    }
}
