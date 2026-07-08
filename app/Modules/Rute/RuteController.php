<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Requests\StoreRuteRequest;
use App\Modules\Rute\Requests\UpdateRuteRequest;
use App\Modules\Rute\Resources\RuteResource;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RuteController extends Controller {
    public function __construct(private readonly RuteService $service) {}

    public function index(Request $request): JsonResponse {
        $idPerusahaan = $request->user()->id_perusahaan;
        if (!$idPerusahaan) {
            return ApiResponse::paginated(collect([]), ['page'=>1,'limit'=>10,'total'=>0,'totalPages'=>0]);
        }
        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->query('page', 1),
            (int) $request->query('limit', 10),
            $request->query('search'),
        );
        return ApiResponse::paginated(RuteResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse {
        $rute = $this->service->findOrFail($id);
        return ApiResponse::success(new RuteResource($rute));
    }

    public function store(StoreRuteRequest $request): JsonResponse {
        $idPerusahaan = $request->user()->id_perusahaan;
        if (!$idPerusahaan) {
            return ApiResponse::error('Pengguna tidak terhubung ke perusahaan', null, 403);
        }
        $data = array_merge($request->validated(), ['id_perusahaan' => $idPerusahaan]);
        $rute = $this->service->create($data);
        return ApiResponse::success(new RuteResource($rute), 'Rute berhasil ditambahkan', 201);
    }

    public function update(UpdateRuteRequest $request, string $id): JsonResponse {
        $rute = $this->service->update($id, $request->validated());
        return ApiResponse::success(new RuteResource($rute), 'Rute berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Rute berhasil dihapus');
    }
}