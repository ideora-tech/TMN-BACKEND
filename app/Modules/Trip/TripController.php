<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Helpers\ApiResponse;
use App\Modules\Trip\Requests\MulaiTripRequest;
use App\Modules\Trip\Requests\StoreTripRequest;
use App\Modules\Trip\Resources\TripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TripController extends Controller
{
    public function __construct(private readonly TripService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_jadwal'),
            $request->get('id_penugasan'),
            $request->get('id_supir'),
            $request->get('search'),
            $request->get('status'),
            $request->get('id_proyek')
        );

        return ApiResponse::paginated(
            TripResource::collection($result['data']),
            $result['meta']
        );
    }

    public function ringkasanProyek(Request $request): JsonResponse
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;

        $result = $this->service->ringkasanProyek(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->get('status')
        );

        return ApiResponse::paginated($result['data'], $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        return ApiResponse::success(new TripResource($this->service->findOrFail($id, $idPerusahaan)));
    }

    public function store(StoreTripRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->create($request->validated(), $idPerusahaan);
        return ApiResponse::success(new TripResource($record), 'Trip berhasil dibuat', 201);
    }

    public function mulai(MulaiTripRequest $request): JsonResponse
    {
        $record = $this->service->mulaiDariPenugasan(
            $request->validated(),
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(new TripResource($record), 'Trip berhasil dimulai', 201);
    }

    public function checkin(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->checkin($id, $idPerusahaan);
        return ApiResponse::success(new TripResource($record), 'Checkin berhasil');
    }

    public function checkout(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->checkout($id, $idPerusahaan, $request->boolean('selesaikan_penugasan'));
        return ApiResponse::success(new TripResource($record), 'Checkout berhasil');
    }

    public function batalkan(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->batalkan($id, $idPerusahaan);
        return ApiResponse::success(new TripResource($record), 'Trip berhasil dibatalkan');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->delete($id, $idPerusahaan);
        return ApiResponse::success(null, 'Trip berhasil dihapus');
    }

    public function rekapBiaya(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $data = $this->service->rekapBiaya($id, $idPerusahaan);
        return ApiResponse::success($data, 'Rekap biaya berhasil dimuat');
    }

    public function settlementIndex(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->settlementList(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_supir'),
            $request->get('status_settlement'),
            $request->get('tanggal_dari'),
            $request->get('tanggal_sampai'),
            $request->get('search'),
        );

        return ApiResponse::paginated($result['data'], $result['meta']);
    }

    public function tandaiLunas(Request $request, string $id): JsonResponse
    {
        $request->validate(['catatan' => ['sometimes', 'nullable', 'string']]);
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->tandaiLunas($id, $idPerusahaan, $request->get('catatan'));
        return ApiResponse::success(new TripResource($record), 'Settlement trip berhasil ditandai lunas');
    }

    public function batalkanLunas(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->batalkanLunas($id, $idPerusahaan);
        return ApiResponse::success(new TripResource($record), 'Status lunas settlement dibatalkan');
    }

    public function updateUangJalan(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['uang_jalan_alokasi' => ['present', 'nullable', 'numeric', 'min:0']]);
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $alokasi = $validated['uang_jalan_alokasi'] !== null ? (float) $validated['uang_jalan_alokasi'] : null;
        $record = $this->service->updateUangJalan($id, $idPerusahaan, $alokasi);
        return ApiResponse::success(new TripResource($record), 'Uang jalan berhasil diperbarui');
    }
}
