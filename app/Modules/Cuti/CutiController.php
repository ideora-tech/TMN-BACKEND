<?php

declare(strict_types=1);

namespace App\Modules\Cuti;

use App\Helpers\ApiResponse;
use App\Modules\Cuti\Requests\PenyesuaianSaldoRequest;
use App\Modules\Cuti\Requests\StoreJenisCutiRequest;
use App\Modules\Cuti\Requests\StorePengajuanCutiRequest;
use App\Modules\Cuti\Requests\UpdateJenisCutiRequest;
use App\Modules\Cuti\Resources\JenisCutiResource;
use App\Modules\Cuti\Resources\PengajuanCutiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CutiController extends Controller
{
    public function __construct(private readonly CutiService $service) {}

    public function indexJenis(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listJenis(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
        );

        return ApiResponse::paginated(
            JenisCutiResource::collection($result['data']),
            $result['meta']
        );
    }

    public function storeJenis(StoreJenisCutiRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->createJenis($idPerusahaan, $request->validated());
        return ApiResponse::success(new JenisCutiResource($record), 'Jenis cuti berhasil dibuat', 201);
    }

    public function updateJenis(UpdateJenisCutiRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->updateJenis($id, $idPerusahaan, $request->validated());
        return ApiResponse::success(new JenisCutiResource($record), 'Jenis cuti berhasil diperbarui');
    }

    public function destroyJenis(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $this->service->deleteJenis($id, $idPerusahaan);
        return ApiResponse::success(null, 'Jenis cuti berhasil dihapus');
    }

    public function indexPengajuan(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listPengajuan(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('status'),
            $request->get('search'),
            $request->get('tanggal_dari'),
            $request->get('tanggal_sampai'),
        );

        return ApiResponse::paginated(
            PengajuanCutiResource::collection($result['data']),
            $result['meta']
        );
    }

    public function storePengajuan(StorePengajuanCutiRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->createPengajuan($idPerusahaan, $request->validated());
        return ApiResponse::success(new PengajuanCutiResource($record), 'Pengajuan cuti berhasil dibuat', 201);
    }

    public function setujui(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->setujui($id, $idPerusahaan);
        return ApiResponse::success(new PengajuanCutiResource($record), 'Pengajuan cuti disetujui');
    }

    public function tolak(Request $request, string $id): JsonResponse
    {
        $request->validate(['catatan' => ['sometimes', 'nullable', 'string']]);
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->tolak($id, $idPerusahaan, $request->get('catatan'));
        return ApiResponse::success(new PengajuanCutiResource($record), 'Pengajuan cuti ditolak');
    }

    public function batalkan(Request $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->batalkan($id, $idPerusahaan);
        return ApiResponse::success(new PengajuanCutiResource($record), 'Pengajuan cuti dibatalkan');
    }

    public function cutiAktif(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $tanggal = (string) $request->get('tanggal', now()->toDateString());
        return ApiResponse::success($this->service->orangCutiPadaTanggal($idPerusahaan, $tanggal));
    }

    public function saldo(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $tahun = (int) $request->get('tahun', now()->format('Y'));

        return ApiResponse::success($this->service->saldoInfo(
            $idPerusahaan,
            $request->get('id_karyawan'),
            $request->get('id_supir'),
            $tahun,
        ));
    }

    public function rekapSaldo(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $tahun = (int) $request->get('tahun', now()->format('Y'));

        $result = $this->service->rekapSaldo(
            $idPerusahaan,
            $tahun,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
        );

        return ApiResponse::paginated($result['data'], $result['meta']);
    }

    public function penyesuaianSaldo(PenyesuaianSaldoRequest $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->penyesuaian($idPerusahaan, $request->validated());
        return ApiResponse::success($record, 'Penyesuaian saldo berhasil disimpan', 201);
    }
}
