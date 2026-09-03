<?php

declare(strict_types=1);

namespace App\Modules\TipePembayaran;

use App\Helpers\ApiResponse;
use App\Modules\TipePembayaran\Requests\StoreTipePembayaranRequest;
use App\Modules\TipePembayaran\Requests\UpdateTipePembayaranRequest;
use App\Modules\TipePembayaran\Resources\TipePembayaranResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TipePembayaranController extends Controller
{
    public function __construct(private readonly TipePembayaranService $service) {}

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
            TipePembayaranResource::collection($result['data']),
            $result['meta']
        );
    }

    /** Daftar aktif tanpa paginasi — dipakai dropdown Invoice Vendor. */
    public function opsiAktif(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(TipePembayaranResource::collection($this->service->listAktif($idPerusahaan)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new TipePembayaranResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StoreTipePembayaranRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new TipePembayaranResource($record), 'Tipe pembayaran berhasil dibuat', 201);
    }

    public function update(UpdateTipePembayaranRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new TipePembayaranResource($record), 'Tipe pembayaran berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Tipe pembayaran berhasil dihapus');
    }
}
