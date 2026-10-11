<?php

declare(strict_types=1);

namespace App\Modules\KasKecil;

use App\Helpers\ApiResponse;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\KasKecil\Exports\BukuKasKecilExport;
use App\Modules\KasKecil\Requests\StorePengeluaranKasKecilRequest;
use App\Modules\KasKecil\Requests\StorePengisianKasKecilRequest;
use App\Modules\KasKecil\Requests\StoreSaldoAwalKasKecilRequest;
use App\Modules\KasKecil\Resources\KasKecilPengisianResource;
use App\Modules\KasKecil\Resources\KasKecilTransaksiResource;
use App\Support\Exports\SheetTabelTautan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KasKecilController extends Controller
{
    public function __construct(private readonly KasKecilService $service) {}

    public function ringkasan(Request $request): JsonResponse
    {
        $ringkasan = $this->service->ringkasan((string) $request->user()->id_perusahaan);
        $ringkasan['boleh_saldo_awal'] = in_array($request->user()->kode_peran, KasKecilService::PERAN_SALDO_AWAL, true);

        return ApiResponse::success($ringkasan);
    }

    public function buku(Request $request): JsonResponse
    {
        $masukan = $this->validasiBuku($request);

        $buku = $this->service->bukuKas(
            (string) $request->user()->id_perusahaan,
            $masukan['dari'] ?? null,
            $masukan['sampai'] ?? null,
        );

        $tersaring = array_reverse($this->service->saring(
            $buku['baris'],
            $masukan['arah'] ?? null,
            $masukan['kategori'] ?? null,
            $masukan['search'] ?? null,
        ));

        $page  = max(1, (int) ($masukan['page'] ?? 1));
        $limit = min(100, max(1, (int) ($masukan['limit'] ?? 10)));
        $total = count($tersaring);

        return ApiResponse::success([
            'periode'      => $buku['periode'],
            'saldo_awal'   => $buku['saldo_awal'],
            'total_masuk'  => $buku['total_masuk'],
            'total_keluar' => $buku['total_keluar'],
            'saldo_akhir'  => $buku['saldo_akhir'],
            'tersaring'    => [
                'jumlah' => $total,
                'masuk'  => round(array_sum(array_map(static fn (object $t) => $t->arah === 'masuk' ? (float) $t->nominal : 0.0, $tersaring)), 2),
                'keluar' => round(array_sum(array_map(static fn (object $t) => $t->arah === 'keluar' ? (float) $t->nominal : 0.0, $tersaring)), 2),
            ],
            'data'         => KasKecilTransaksiResource::collection(array_slice($tersaring, ($page - 1) * $limit, $limit)),
            'meta'         => [
                'page'       => $page,
                'limit'      => $limit,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / $limit)),
            ],
        ]);
    }

    public function exportBuku(Request $request, ArusKasService $arusKasService): BinaryFileResponse
    {
        $masukan = $this->validasiBuku($request);

        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $buku = $this->service->bukuKas($idPerusahaan, $masukan['dari'] ?? null, $masukan['sampai'] ?? null);

        SheetTabelTautan::pastikanBisaDiexport(count($buku['baris']));

        return Excel::download(
            new BukuKasKecilExport($buku, $arusKasService->namaPerusahaan($idPerusahaan)),
            'buku-kas-kecil-' . date('Ymd') . '.xlsx',
        );
    }

    public function showTransaksi(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new KasKecilTransaksiResource(
            $this->service->findTransaksiOrFail($id, (string) $request->user()->id_perusahaan)
        ));
    }

    public function storeTransaksi(StorePengeluaranKasKecilRequest $request): JsonResponse
    {
        $record = $this->service->createPengeluaran(
            $request->safe()->except('bukti'),
            (string) $request->user()->id_perusahaan,
            $request->file('bukti'),
        );

        return ApiResponse::success(new KasKecilTransaksiResource($record), 'Pengeluaran kas kecil berhasil dicatat', 201);
    }

    public function updateTransaksi(StorePengeluaranKasKecilRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updatePengeluaran(
            $id,
            $request->safe()->except('bukti'),
            (string) $request->user()->id_perusahaan,
            $request->file('bukti'),
        );

        return ApiResponse::success(new KasKecilTransaksiResource($record), 'Pengeluaran kas kecil berhasil diperbarui');
    }

    public function destroyTransaksi(Request $request, string $id): JsonResponse
    {
        $this->service->deletePengeluaran($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Pengeluaran kas kecil berhasil dihapus');
    }

    public function storeSaldoAwal(StoreSaldoAwalKasKecilRequest $request): JsonResponse
    {
        $record = $this->service->catatSaldoAwal($request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KasKecilTransaksiResource($record), 'Saldo awal kas kecil berhasil dicatat', 201);
    }

    public function destroySaldoAwal(Request $request): JsonResponse
    {
        $this->service->hapusSaldoAwal((string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Saldo awal kas kecil berhasil dihapus');
    }

    public function indexPengisian(Request $request): JsonResponse
    {
        $result = $this->service->listPengisian(
            (string) $request->user()->id_perusahaan,
            max(1, (int) $request->get('page', 1)),
            min(100, max(1, (int) $request->get('limit', 10))),
            $this->teks($request, 'search'),
            $this->teks($request, 'status'),
        );

        return ApiResponse::paginated(KasKecilPengisianResource::collection($result['data']), $result['meta']);
    }

    public function showPengisian(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new KasKecilPengisianResource(
            $this->service->findPengisianOrFail($id, (string) $request->user()->id_perusahaan)
        ));
    }

    public function riwayatPengisian(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->service->riwayatPengisian($id, (string) $request->user()->id_perusahaan));
    }

    public function storePengisian(StorePengisianKasKecilRequest $request): JsonResponse
    {
        $record = $this->service->createPengisian($request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KasKecilPengisianResource($record), 'Pengisian kas kecil berhasil diajukan', 201);
    }

    public function updatePengisian(StorePengisianKasKecilRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updatePengisian($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KasKecilPengisianResource($record), 'Pengisian kas kecil berhasil diperbarui');
    }

    public function destroyPengisian(Request $request, string $id): JsonResponse
    {
        $this->service->deletePengisian($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Pengisian kas kecil berhasil dihapus');
    }

    /** @return array<string, mixed> */
    private function validasiBuku(Request $request): array
    {
        return $request->validate([
            'dari'     => ['nullable', 'date_format:Y-m-d'],
            'sampai'   => ['nullable', 'date_format:Y-m-d'],
            'arah'     => ['nullable', 'in:masuk,keluar'],
            'kategori' => ['nullable', 'string', 'max:30'],
            'search'   => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'dari.date_format'   => 'Format tanggal dari harus YYYY-MM-DD',
            'sampai.date_format' => 'Format tanggal sampai harus YYYY-MM-DD',
            'arah.in'            => 'Arah transaksi tidak dikenal',
        ]);
    }

    private function teks(Request $request, string $kunci): ?string
    {
        $nilai = $request->query($kunci);

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }
}
