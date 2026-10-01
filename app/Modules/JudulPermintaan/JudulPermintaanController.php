<?php

declare(strict_types=1);

namespace App\Modules\JudulPermintaan;

use App\Helpers\ApiResponse;
use App\Modules\JudulPermintaan\Requests\StoreJudulPermintaanRequest;
use App\Modules\JudulPermintaan\Requests\UpdateJudulPermintaanRequest;
use App\Modules\JudulPermintaan\Resources\JudulPermintaanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JudulPermintaanController extends Controller
{
    public function __construct(private readonly JudulPermintaanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $aktifRaw = $request->get('aktif');
        $aktif = ($aktifRaw === null || $aktifRaw === '') ? null : (bool) ((int) $aktifRaw);

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $aktif
        );

        return ApiResponse::paginated(
            JudulPermintaanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function opsiAktif(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(JudulPermintaanResource::collection($this->service->listAktif($idPerusahaan)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new JudulPermintaanResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StoreJudulPermintaanRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new JudulPermintaanResource($record), 'Judul permintaan berhasil dibuat', 201);
    }

    public function update(UpdateJudulPermintaanRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new JudulPermintaanResource($record), 'Judul permintaan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Judul permintaan berhasil dihapus');
    }
}
