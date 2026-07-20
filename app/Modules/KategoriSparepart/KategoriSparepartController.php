<?php
// app/Modules/KategoriSparepart/KategoriSparepartController.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart;

use App\Helpers\ApiResponse;
use App\Modules\KategoriSparepart\Requests\StoreKategoriSparepartRequest;
use App\Modules\KategoriSparepart\Requests\UpdateKategoriSparepartRequest;
use App\Modules\KategoriSparepart\Resources\KategoriSparepartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KategoriSparepartController extends Controller
{
    public function __construct(private readonly KategoriSparepartService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            KategoriSparepartResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new KategoriSparepartResource($record));
    }

    public function store(StoreKategoriSparepartRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KategoriSparepartResource($record), 'Kategori sparepart berhasil dibuat', 201);
    }

    public function update(UpdateKategoriSparepartRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new KategoriSparepartResource($record), 'Kategori sparepart berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Kategori sparepart berhasil dihapus');
    }
}
