<?php

declare(strict_types=1);

namespace App\Modules\PerusahaanGrup;

use App\Helpers\ApiResponse;
use App\Modules\PerusahaanGrup\Requests\StorePerusahaanGrupRequest;
use App\Modules\PerusahaanGrup\Requests\UpdatePerusahaanGrupRequest;
use App\Modules\PerusahaanGrup\Resources\PerusahaanGrupResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PerusahaanGrupController extends Controller
{
    public function __construct(private readonly PerusahaanGrupService $service) {}

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

        return ApiResponse::paginated(PerusahaanGrupResource::collection($result['data']), $result['meta']);
    }

    public function opsiAktif(Request $request): JsonResponse
    {
        return ApiResponse::success(PerusahaanGrupResource::collection($this->service->listAktif((string) $request->user()->id_perusahaan)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new PerusahaanGrupResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StorePerusahaanGrupRequest $request): JsonResponse
    {
        $data = array_merge($request->validated(), ['id_perusahaan' => (string) $request->user()->id_perusahaan]);

        return ApiResponse::success(new PerusahaanGrupResource($this->service->create($data)), 'Perusahaan grup berhasil dibuat', 201);
    }

    public function update(UpdatePerusahaanGrupRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new PerusahaanGrupResource($record), 'Perusahaan grup berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Perusahaan grup berhasil dihapus');
    }
}
