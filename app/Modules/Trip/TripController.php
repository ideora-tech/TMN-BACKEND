<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Helpers\ApiResponse;
use App\Modules\Penugasan\Resources\PenugasanResource;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Modules\Trip\Exports\RekapTripSupirExport;
use App\Modules\Trip\Exports\RiwayatTripExport;
use App\Modules\Trip\Requests\MulaiTripRequest;
use App\Modules\Trip\Requests\StoreTripRequest;
use App\Modules\Trip\Resources\TripResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TripController extends Controller
{
    public function __construct(
        private readonly TripService $service,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

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
            $request->get('id_proyek'),
            $request->get('tanggal_dari'),
            $request->get('tanggal_sampai'),
            $request->get('sumber')
        );

        $map = $this->service->titikDropTripBanyak(array_map(fn ($r) => (string) $r->id_trip, [...$result['data']]));
        foreach ($result['data'] as $row) {
            $row->titik_drop = $map[$row->id_trip] ?? [];
        }

        return ApiResponse::paginated(
            TripResource::collection($result['data']),
            $result['meta']
        );
    }

    public function penugasanSaya(Request $request, string $idPenugasan): JsonResponse
    {
        $validated = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        $result = $this->service->detailPenugasanUntukSupir(
            $idPenugasan,
            (string) $supir->id_supir,
            (string) $request->user()->id_perusahaan,
            $validated['tanggal'] ?? null
        );

        return ApiResponse::success([
            'penugasan' => new PenugasanResource($result['penugasan']),
            'trip' => $result['trip'] !== null ? new TripResource($result['trip']) : null,
            'rute_tersedia' => $result['rute_tersedia']->map(fn ($r) => [
                'id_rute'   => $r->id_rute,
                'nama_rute' => $r->nama_rute,
                'asal'      => $r->asal,
                'tujuan'    => $r->tujuan,
            ])->values(),
            'nama_klien' => $result['nama_klien'],
            'shift_hari_ini' => $result['shift_hari_ini'],
            'armada_hari_ini' => $result['armada_hari_ini'],
            'jumlah_trip_selesai_hari_ini' => $result['jumlah_trip_selesai_hari_ini'],
        ]);
    }

    public function jadwalSaya(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dari'   => ['required', 'date_format:Y-m-d'],
            'sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);

        if (Carbon::parse($validated['dari'])->diffInDays(Carbon::parse($validated['sampai'])) > 62) {
            abort(422, 'Rentang tanggal maksimal 62 hari');
        }

        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        return ApiResponse::success(
            $this->service->jadwalUntukSupir((string) $supir->id_supir, $validated['dari'], $validated['sampai'])
        );
    }

    public function riwayatSaya(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'status'  => ['nullable', 'in:selesai,dibatalkan'],
        ]);

        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        $tanggal = $validated['tanggal'] ?? null;

        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            null,
            null,
            (string) $supir->id_supir,
            null,
            $validated['status'] ?? 'selesai,dibatalkan',
            null,
            $tanggal,
            $tanggal
        );

        return ApiResponse::paginated(TripResource::collection($result['data']), $result['meta']);
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
        $trip = $this->service->findOrFail($id, $idPerusahaan);
        $trip->titik_drop        = $this->service->titikDropTrip($id);
        $trip->sudah_difakturkan = $this->service->tripPunyaFakturAktif($id);
        return ApiResponse::success(new TripResource($trip));
    }

    public function updateTitikDrop(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'titik_drop'   => ['present', 'array', 'max:10'],
            'titik_drop.*' => ['required', 'string', 'max:200'],
        ]);

        $this->service->updateTitikDrop($id, $validated['titik_drop'], (string) $request->user()->id_perusahaan);
        return ApiResponse::success(['titik_drop' => $validated['titik_drop']], 'Titik drop diperbarui');
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

    public function mulaiSaya(MulaiTripRequest $request): JsonResponse
    {
        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        $record = $this->service->mulaiDariPenugasanUntukSupir(
            $request->validated(),
            (string) $supir->id_supir,
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(new TripResource($record), 'Trip berhasil dimulai', 201);
    }

    public function checkoutSaya(Request $request, string $idTrip): JsonResponse
    {
        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);
        if ($supir === null) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        $record = $this->service->checkoutUntukSupir($idTrip, (string) $supir->id_supir, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new TripResource($record), 'Trip berhasil diselesaikan');
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

    private function rekapFilter(Request $request): array
    {
        return [
            'dari'   => $request->query('dari'),
            'sampai' => $request->query('sampai'),
            'sumber' => $request->query('sumber'),
            'status' => $request->query('status'),
        ];
    }

    public function exportRekapSupirExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;
        $filter = $this->rekapFilter($request);
        $items = $this->service->rekapSupir($idPerusahaan, $filter);

        return Excel::download(
            new RekapTripSupirExport($items, $filter['dari'], $filter['sampai']),
            'rekap-trip-supir-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportRekapSupirPdf(Request $request): Response
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;
        $filter = $this->rekapFilter($request);
        $items = $this->service->rekapSupir($idPerusahaan, $filter);

        $pdf = Pdf::loadView('exports.rekap-trip-supir', [
            'items'      => $items,
            'periode'    => RekapTripSupirExport::labelPeriode($filter['dari'], $filter['sampai']),
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan($idPerusahaan),
        ]);

        return $pdf->download('rekap-trip-supir-' . date('Ymd') . '.pdf');
    }

    private function riwayatFilter(Request $request): array
    {
        return [
            'dari'   => $request->query('dari'),
            'sampai' => $request->query('sampai'),
            'sumber' => $request->query('sumber'),
            'status' => $request->query('status'),
            'search' => $request->query('search'),
        ];
    }

    public function exportRiwayatExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;
        $filter = $this->riwayatFilter($request);
        $trips = $this->service->riwayatUntukExport($idPerusahaan, $filter);
        $rekap = $this->service->rekapSupir($idPerusahaan, $this->rekapFilter($request));

        return Excel::download(
            new RiwayatTripExport($trips, $rekap, $filter['dari'], $filter['sampai']),
            'riwayat-trip-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportRiwayatPdf(Request $request): Response
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;
        $filter = $this->riwayatFilter($request);
        $trips = $this->service->riwayatUntukExport($idPerusahaan, $filter);

        $pdf = Pdf::loadView('exports.riwayat-trip', [
            'items'      => $trips,
            'periode'    => RekapTripSupirExport::labelPeriode($filter['dari'], $filter['sampai']),
            'logoBase64' => $this->logoBase64(),
            'perusahaan' => $this->service->dataPerusahaan($idPerusahaan),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('riwayat-trip-' . date('Ymd') . '.pdf');
    }

    private function logoBase64(): ?string
    {
        $path = public_path('img/logo/logo-sli.png');
        if (!is_file($path)) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }
}
