<?php

declare(strict_types=1);

namespace App\Modules\ArusKas;

use App\Helpers\ApiResponse;
use App\Modules\ArusKas\Exports\ArusKasExport;
use App\Modules\ArusKas\Requests\StorePemasukanRequest;
use App\Modules\ArusKas\Requests\StorePengajuanRequest;
use App\Modules\ArusKas\Requests\TolakPengajuanRequest;
use App\Modules\ArusKas\Requests\TransferPengajuanRequest;
use App\Modules\ArusKas\Requests\UpdatePemasukanRequest;
use App\Modules\ArusKas\Requests\UpdatePengajuanRequest;
use App\Modules\ArusKas\Resources\PemasukanGabunganResource;
use App\Modules\ArusKas\Resources\PemasukanResource;
use App\Modules\ArusKas\Resources\PengajuanPengeluaranResource;
use App\Modules\ArusKas\Resources\TransaksiArusKasResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArusKasController extends Controller
{
    public function __construct(private readonly ArusKasService $service) {}

    public function rekap(Request $request): JsonResponse
    {
        $hasil = $this->service->rekap(
            (string) $request->user()->id_perusahaan,
            $request->get('dari'),
            $request->get('sampai'),
            $request->get('arah'),
            $request->get('sumber'),
        );

        return ApiResponse::success([
            'ringkasan' => $hasil['ringkasan'],
            'transaksi' => TransaksiArusKasResource::collection($hasil['transaksi']),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $dari   = $request->get('dari') ?: now()->startOfMonth()->toDateString();
        $sampai = $request->get('sampai') ?: now()->endOfMonth()->toDateString();

        $hasil = $this->service->rekap(
            (string) $request->user()->id_perusahaan,
            $dari,
            $sampai,
            $request->get('arah'),
            $request->get('sumber'),
        );

        return Excel::download(
            new ArusKasExport($hasil['transaksi'], $dari, $sampai),
            'arus-kas-' . date('Ymd') . '.xlsx'
        );
    }

    public function indexPengajuan(Request $request): JsonResponse
    {
        $data = $this->service->listPengajuan(
            (string) $request->user()->id_perusahaan,
            $request->get('status')
        );
        return ApiResponse::success(PengajuanPengeluaranResource::collection($data));
    }

    public function showPengajuan(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findPengajuanOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PengajuanPengeluaranResource($record));
    }

    public function storePengajuan(StorePengajuanRequest $request): JsonResponse
    {
        $record = $this->service->createPengajuan(
            $request->safe()->except('bukti'),
            (string) $request->user()->id_perusahaan,
            $request->file('bukti')
        );
        return ApiResponse::success(new PengajuanPengeluaranResource($record), 'Pengajuan pengeluaran berhasil dibuat', 201);
    }

    public function updatePengajuan(UpdatePengajuanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updatePengajuan(
            $id,
            $request->safe()->except('bukti'),
            (string) $request->user()->id_perusahaan,
            $request->file('bukti')
        );
        return ApiResponse::success(new PengajuanPengeluaranResource($record), 'Pengajuan pengeluaran berhasil diperbarui');
    }

    public function destroyPengajuan(Request $request, string $id): JsonResponse
    {
        $this->service->deletePengajuan($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Pengajuan pengeluaran berhasil dihapus');
    }

    public function cekPengajuan(Request $request, string $id): JsonResponse
    {
        $record = $this->service->cek($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PengajuanPengeluaranResource($record), 'Pengajuan ditandai sudah dicek');
    }

    public function setujuiPengajuan(Request $request, string $id): JsonResponse
    {
        $record = $this->service->setujui($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PengajuanPengeluaranResource($record), 'Pengajuan disetujui');
    }

    public function tolakPengajuan(TolakPengajuanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->tolak($id, (string) $request->validated('alasan'), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PengajuanPengeluaranResource($record), 'Pengajuan ditolak');
    }

    public function transferPengajuan(TransferPengajuanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->transfer(
            $id,
            (string) $request->validated('tanggal_transfer'),
            $request->file('bukti'),
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(new PengajuanPengeluaranResource($record), 'Pengajuan pengeluaran berhasil ditransfer');
    }

    public function storePemasukan(StorePemasukanRequest $request): JsonResponse
    {
        $record = $this->service->createPemasukan(
            $request->safe()->except('bukti'),
            (string) $request->user()->id_perusahaan,
            $request->file('bukti')
        );
        return ApiResponse::success(new PemasukanResource($record), 'Pemasukan berhasil dicatat', 201);
    }

    public function updatePemasukan(UpdatePemasukanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updatePemasukan(
            $id,
            $request->safe()->except('bukti'),
            (string) $request->user()->id_perusahaan,
            $request->file('bukti')
        );
        return ApiResponse::success(new PemasukanResource($record), 'Pemasukan berhasil diperbarui');
    }

    public function destroyPemasukan(Request $request, string $id): JsonResponse
    {
        $this->service->deletePemasukan($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Pemasukan berhasil dihapus');
    }

    public function indexPemasukan(Request $request): JsonResponse
    {
        $data = $this->service->listPemasukan(
            (string) $request->user()->id_perusahaan,
            $request->get('dari'),
            $request->get('sampai'),
            $request->get('jenis'),
            $request->get('kategori'),
        );
        return ApiResponse::success(PemasukanGabunganResource::collection(collect($data)));
    }
}
