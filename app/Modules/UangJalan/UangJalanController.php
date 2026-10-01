<?php

declare(strict_types=1);

namespace App\Modules\UangJalan;

use App\Helpers\ApiResponse;
use App\Modules\UangJalan\Requests\StoreUangJalanRequest;
use App\Modules\UangJalan\Requests\UpdateUangJalanRequest;
use App\Modules\UangJalan\Resources\UangJalanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UangJalanController extends Controller
{
    public function __construct(private readonly UangJalanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            max(1, (int) $request->get('page', 1)),
            min(100, max(1, (int) $request->get('limit', 10))),
            $this->teks($request, 'search'),
            $this->teks($request, 'status'),
            $this->teks($request, 'dari'),
            $this->teks($request, 'sampai'),
        );

        return ApiResponse::paginated(UangJalanResource::collection($result['data']), $result['meta']);
    }

    public function opsi(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->opsi((string) $request->user()->id_perusahaan));
    }

    public function opsiVendor(Request $request, string $idVendor): JsonResponse
    {
        return ApiResponse::success($this->service->opsiVendor($idVendor, (string) $request->user()->id_perusahaan));
    }

    private function teks(Request $request, string $kunci): ?string
    {
        $nilai = $request->query($kunci);

        return is_string($nilai) ? $nilai : null;
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new UangJalanResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function riwayat(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->service->riwayat($id, (string) $request->user()->id_perusahaan));
    }

    public function store(StoreUangJalanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new UangJalanResource($record), 'Uang jalan berhasil dibuat', 201);
    }

    public function update(UpdateUangJalanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new UangJalanResource($record), 'Uang jalan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Uang jalan berhasil dihapus');
    }
}
