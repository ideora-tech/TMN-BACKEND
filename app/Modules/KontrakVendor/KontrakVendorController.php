<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Helpers\ApiResponse;
use App\Modules\KontrakVendor\Requests\StoreKontrakVendorRequest;
use App\Modules\KontrakVendor\Requests\UpdateKontrakVendorRequest;
use App\Modules\KontrakVendor\Resources\KontrakVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KontrakVendorController extends Controller
{
    public function __construct(private readonly KontrakVendorService $service) {}

    /**
     * List all kontrak vendor for the authenticated perusahaan.
     * GET /api/v1/kontrak-vendor
     */
    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_vendor')
        );

        return ApiResponse::paginated(
            KontrakVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    /**
     * List kontrak vendor scoped to a specific proyek.
     * GET /api/v1/proyek/{idProyek}/kontrak
     */
    public function indexByProyek(Request $request, string $idProyek): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listByProyek(
            $idPerusahaan,
            $idProyek,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            KontrakVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new KontrakVendorResource($this->service->findOrFail($id)));
    }

    /**
     * Create kontrak vendor (scoped to a proyek via URL or body).
     * POST /api/v1/proyek/{idProyek}/kontrak
     */
    public function storeForProyek(StoreKontrakVendorRequest $request, string $idProyek): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            [
                'id_perusahaan' => (string) $request->user()->id_perusahaan,
                'id_proyek'     => $idProyek,
            ]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KontrakVendorResource($record), 'Kontrak vendor berhasil dibuat', 201);
    }

    /**
     * Create kontrak vendor without a proyek (standalone).
     * POST /api/v1/kontrak-vendor
     */
    public function store(StoreKontrakVendorRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KontrakVendorResource($record), 'Kontrak vendor berhasil dibuat', 201);
    }

    public function update(UpdateKontrakVendorRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new KontrakVendorResource($record), 'Kontrak vendor berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Kontrak vendor berhasil dihapus');
    }
}
