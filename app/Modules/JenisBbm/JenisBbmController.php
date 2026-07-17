<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm;

use App\Helpers\ApiResponse;
use App\Modules\JenisBbm\Requests\StoreHargaBbmRequest;
use App\Modules\JenisBbm\Requests\StoreJenisBbmRequest;
use App\Modules\JenisBbm\Requests\UpdateJenisBbmRequest;
use App\Modules\JenisBbm\Resources\HargaBbmResource;
use App\Modules\JenisBbm\Resources\JenisBbmResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JenisBbmController extends Controller
{
    public function __construct(private readonly JenisBbmService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search')
        );

        return ApiResponse::paginated(
            JenisBbmResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->findOrFail($id, $idPerusahaan);
        return ApiResponse::success(new JenisBbmResource($this->service->attachHargaEfektif($record)));
    }

    public function store(StoreJenisBbmRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new JenisBbmResource($record), 'Jenis BBM berhasil dibuat', 201);
    }

    public function update(UpdateJenisBbmRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new JenisBbmResource($record), 'Jenis BBM berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Jenis BBM berhasil dihapus');
    }

    public function riwayatHarga(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $riwayat = $this->service->riwayatHarga($id, $idPerusahaan);
        return ApiResponse::success(HargaBbmResource::collection($riwayat));
    }

    public function tambahHarga(StoreHargaBbmRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->tambahHarga($id, $idPerusahaan, $request->validated());
        return ApiResponse::success(new HargaBbmResource($record), 'Harga BBM berhasil ditambahkan', 201);
    }
}
