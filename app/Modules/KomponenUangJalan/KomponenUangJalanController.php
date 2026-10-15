<?php

declare(strict_types=1);

namespace App\Modules\KomponenUangJalan;

use App\Helpers\ApiResponse;
use App\Modules\KomponenUangJalan\Requests\SimpanKomponenUangJalanRequest;
use App\Modules\KomponenUangJalan\Resources\KomponenUangJalanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KomponenUangJalanController extends Controller
{
    public function __construct(private readonly KomponenUangJalanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $aktifRaw = $request->query('aktif');
        $search   = $request->query('search');

        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            max(1, (int) $request->query('page', 1)),
            min(100, max(1, (int) $request->query('limit', 10))),
            is_string($search) && trim($search) !== '' ? trim($search) : null,
            ($aktifRaw === null || $aktifRaw === '') ? null : (bool) ((int) $aktifRaw),
        );

        return ApiResponse::paginated(KomponenUangJalanResource::collection($result['data']), $result['meta']);
    }

    public function opsi(Request $request): JsonResponse
    {
        return ApiResponse::success(KomponenUangJalanResource::collection($this->service->listAktif((string) $request->user()->id_perusahaan)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new KomponenUangJalanResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(SimpanKomponenUangJalanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KomponenUangJalanResource($record), 'Komponen uang jalan berhasil dibuat', 201);
    }

    public function update(SimpanKomponenUangJalanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KomponenUangJalanResource($record), 'Komponen uang jalan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Komponen uang jalan berhasil dihapus');
    }
}
