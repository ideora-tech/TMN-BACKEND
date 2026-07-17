<?php

declare(strict_types=1);

namespace App\Modules\TarifRute;

use App\Helpers\ApiResponse;
use App\Modules\TarifRute\Requests\StoreTarifRuteRequest;
use App\Modules\TarifRute\Requests\UpdateTarifRuteRequest;
use App\Modules\TarifRute\Resources\TarifRuteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TarifRuteController extends Controller
{
    public function __construct(private readonly TarifRuteService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->query('page', 1),
            (int) $request->query('limit', 10),
            [
                'id_rute'            => $request->query('id_rute'),
                'id_jenis_kendaraan' => $request->query('id_jenis_kendaraan'),
                'id_klien'           => $request->query('id_klien'),
                'berlaku'            => $request->query('berlaku'),
                'search'             => $request->query('search'),
            ],
        );

        return ApiResponse::paginated(TarifRuteResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findDetailOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new TarifRuteResource($record));
    }

    public function store(StoreTarifRuteRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan],
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new TarifRuteResource($record), 'Tarif berhasil ditambahkan', 201);
    }

    public function update(UpdateTarifRuteRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new TarifRuteResource($record), 'Tarif berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Tarif berhasil dihapus');
    }

    public function resolusi(Request $request): JsonResponse
    {
        $request->validate([
            'id_rute'            => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['required', 'string', 'max:36'],
            'id_klien'           => ['sometimes', 'nullable', 'string', 'max:36'],
            'tanggal'            => ['sometimes', 'nullable', 'date'],
        ]);

        $tarif = $this->service->resolusi(
            (string) $request->user()->id_perusahaan,
            (string) $request->query('id_rute'),
            (string) $request->query('id_jenis_kendaraan'),
            $request->query('id_klien'),
            $request->query('tanggal'),
        );

        return ApiResponse::success($tarif !== null ? new TarifRuteResource($tarif) : null);
    }

    public function estimasiBok(Request $request): JsonResponse
    {
        $request->validate([
            'id_rute'            => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['required', 'string', 'max:36'],
            'estimasi_tol'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $estimasi = $this->service->estimasiBok(
            (string) $request->user()->id_perusahaan,
            (string) $request->query('id_rute'),
            (string) $request->query('id_jenis_kendaraan'),
            $request->query('estimasi_tol') !== null ? (float) $request->query('estimasi_tol') : null,
        );

        return ApiResponse::success($estimasi);
    }
}
