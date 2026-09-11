<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Helpers\ApiResponse;
use App\Modules\Supir\Exports\RiwayatArmadaSupirExport;
use App\Modules\Supir\Exports\SupirTemplateExport;
use App\Modules\Supir\Requests\ImportSupirRequest;
use App\Modules\Supir\Requests\StoreSupirRequest;
use App\Modules\Supir\Requests\UpdateSupirRequest;
use App\Modules\Supir\Resources\SupirResource;
use App\Modules\Trip\Exports\RiwayatTripSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupirController extends Controller
{
    public function __construct(private readonly SupirService $service) {}

    public function downloadTemplate(): BinaryFileResponse
    {
        return Excel::download(new SupirTemplateExport(), 'template-import-supir.xlsx');
    }

    public function import(ImportSupirRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $result = $this->service->import($request->file('file'), $idPerusahaan);

        return ApiResponse::success($result, 'Import supir selesai diproses');
    }

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $status = $request->get('status') !== null ? (string) $request->get('status') : null;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $status,
            $request->get('search')
        );

        return ApiResponse::paginated(SupirResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new SupirResource($this->service->detail($id, (string) $request->user()->id_perusahaan)));
    }

    public function exportRiwayatArmada(Request $request, string $id, RiwayatSupirService $riwayat): BinaryFileResponse
    {
        $hasil = $riwayat->riwayatArmada($id, (string) $request->user()->id_perusahaan);

        return Excel::download(
            new RiwayatArmadaSupirExport($hasil['data'], (string) $hasil['supir']->nama),
            'riwayat-armada-' . Str::slug((string) $hasil['supir']->nama) . '-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportRiwayatTrip(Request $request, string $id, RiwayatSupirService $riwayat): BinaryFileResponse
    {
        $hasil = $riwayat->riwayatTrip($id, (string) $request->user()->id_perusahaan);

        return Excel::download(
            new RiwayatTripSheet($hasil['data'], null, null, 'Supir: ' . $hasil['supir']->nama),
            'riwayat-trip-' . Str::slug((string) $hasil['supir']->nama) . '-' . date('Ymd') . '.xlsx'
        );
    }

    public function dokumen(Request $request, string $id, DokumenSupirSayaService $dokumenSaya): JsonResponse
    {
        $supir = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success($dokumenSaya->dokumenUntukSupir($supir));
    }

    public function dokumenSaya(Request $request, DokumenSupirSayaService $dokumenSaya): JsonResponse
    {
        return ApiResponse::success($dokumenSaya->dokumenSaya((string) $request->user()->id_pengguna));
    }

    public function unitSaya(Request $request, DokumenSupirSayaService $dokumenSaya): JsonResponse
    {
        return ApiResponse::success($dokumenSaya->unitSaya((string) $request->user()->id_pengguna));
    }

    public function me(Request $request): JsonResponse
    {
        $idPengguna = (string) $request->user()->id_pengguna;

        $supirVendor = app(\App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface::class)
            ->findByPengguna($idPengguna);
        if ($supirVendor !== null) {
            return ApiResponse::success([
                'id_supir'           => $supirVendor->id_supir_vendor,
                'id_pengguna'        => $supirVendor->id_pengguna,
                'nama'               => $supirVendor->nama,
                'no_sim'             => $supirVendor->no_sim,
                'jenis_sim'          => null,
                'tgl_kadaluarsa_sim' => $supirVendor->masa_berlaku_sim,
                'telepon'            => $supirVendor->telepon,
                'status'             => (int) $supirVendor->aktif === 1 ? 'aktif' : 'tidak_aktif',
                'foto'               => null,
                'id_armada_default'  => null,
                'armada_default'     => null,
                'tipe_supir'         => 'vendor',
            ]);
        }

        $record = $this->service->findByPenggunaOrFail($idPengguna);
        return ApiResponse::success(new SupirResource($record));
    }

    public function opsiPengguna(Request $request): JsonResponse
    {
        $result = $this->service->listOpsiPengguna((string) $request->user()->id_perusahaan);
        return ApiResponse::success($result);
    }

    public function store(StoreSupirRequest $request): JsonResponse
    {
        $data = array_merge($request->validated(), ['id_perusahaan' => (string) $request->user()->id_perusahaan]);
        $record = $this->service->create($data);
        return ApiResponse::success(new SupirResource($record), 'Supir berhasil dibuat', 201);
    }

    public function update(UpdateSupirRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new SupirResource($record), 'Supir berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Supir berhasil dihapus');
    }
}
