<?php

declare(strict_types=1);

namespace App\Modules\LaporanPemasukan;

use App\Modules\LaporanPemasukan\Contracts\LaporanPemasukanRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LaporanPemasukanRepository implements LaporanPemasukanRepositoryInterface
{
    private const TANGGAL_FAKTUR = 'COALESCE(faktur.tanggal_faktur, DATE(faktur.dibuat_pada))';

    public function paginate(string $idPerusahaan, string $dari, string $sampai, array $filter, int $page, int $limit): LengthAwarePaginator
    {
        return $this->tersaring($idPerusahaan, $dari, $sampai, $filter)
            ->orderByDesc('x.tanggal')
            ->orderByDesc('x.nomor')
            ->orderByDesc('x.id_baris')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function semua(string $idPerusahaan, string $dari, string $sampai, array $filter): Collection
    {
        return $this->tersaring($idPerusahaan, $dari, $sampai, $filter)
            ->orderBy('x.tanggal')
            ->orderBy('x.nomor')
            ->orderBy('x.id_baris')
            ->get();
    }

    public function rekapKategori(string $idPerusahaan, string $dari, string $sampai, array $filter): Collection
    {
        return $this->tersaring($idPerusahaan, $dari, $sampai, $filter)
            ->groupBy('x.sumber', 'x.kategori')
            ->selectRaw('x.sumber, x.kategori, COUNT(*) as jumlah, SUM(x.nominal) as total, SUM(x.potongan) as potongan')
            ->get();
    }

    public function invoiceTerbit(string $idPerusahaan, string $dari, string $sampai): object
    {
        return DB::table('faktur')
            ->whereNull('faktur.dihapus_pada')
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->where('faktur.status', '!=', 'batal')
            ->whereRaw(self::TANGGAL_FAKTUR . ' BETWEEN ? AND ?', [$dari, $sampai])
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(faktur.total), 0) as total')
            ->first();
    }

    private function tersaring(string $idPerusahaan, string $dari, string $sampai, array $filter): Builder
    {
        $query = DB::query()->fromSub(
            $this->queryPembayaranInvoice($idPerusahaan, $dari, $sampai)
                ->unionAll($this->queryPemasukanManual($idPerusahaan, $dari, $sampai)),
            'x',
        );

        $kategori = $filter['kategori'] ?? null;
        if ($kategori !== null && $kategori !== '') {
            $query->where('x.kategori', $kategori);
        }

        $search = $filter['search'] ?? null;
        if ($search !== null && $search !== '') {
            $pola = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $query->where(function ($q) use ($pola) {
                $q->whereRaw("x.nomor LIKE ? ESCAPE '!'", [$pola])
                    ->orWhereRaw("x.pihak LIKE ? ESCAPE '!'", [$pola])
                    ->orWhereRaw("x.keterangan LIKE ? ESCAPE '!'", [$pola])
                    ->orWhereRaw("x.no_referensi LIKE ? ESCAPE '!'", [$pola]);
            });
        }

        return $query;
    }

    private function queryPembayaranInvoice(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('pembayaran_faktur as pf')
            ->join('faktur as f', 'f.id_faktur', '=', 'pf.id_faktur')
            ->leftJoin('klien as k', 'k.id_klien', '=', 'f.id_klien')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'pf.dibuat_oleh')
            ->whereNull('pf.dihapus_pada')
            ->whereNull('f.dihapus_pada')
            ->where('f.id_perusahaan', $idPerusahaan)
            ->where('f.status', '!=', 'batal')
            ->whereBetween('pf.tanggal_bayar', [$dari, $sampai])
            ->selectRaw("
                pf.id_pembayaran_faktur as id_baris,
                pf.tanggal_bayar as tanggal,
                'invoice' as sumber,
                '" . LaporanPemasukanService::KATEGORI_INVOICE . "' as kategori,
                f.nomor_faktur as nomor,
                k.nama_klien as pihak,
                pf.catatan as keterangan,
                pf.no_referensi as no_referensi,
                pf.nominal as nominal,
                pf.potongan as potongan,
                pf.keterangan_potongan as keterangan_potongan,
                pf.url_bukti as url_bukti,
                u.username as oleh,
                f.id_faktur as id_dokumen,
                NULL as id_kasbon
            ");
    }

    private function queryPemasukanManual(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('pemasukan as pm')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'pm.dibuat_oleh')
            ->whereNull('pm.dihapus_pada')
            ->where('pm.id_perusahaan', $idPerusahaan)
            ->whereBetween('pm.tanggal', [$dari, $sampai])
            ->selectRaw("
                pm.id_pemasukan as id_baris,
                pm.tanggal as tanggal,
                'manual' as sumber,
                pm.kategori as kategori,
                pm.nomor_pemasukan as nomor,
                pm.sumber_dana as pihak,
                pm.keterangan as keterangan,
                NULL as no_referensi,
                pm.nominal as nominal,
                0 as potongan,
                NULL as keterangan_potongan,
                pm.url_bukti as url_bukti,
                u.username as oleh,
                pm.id_pemasukan as id_dokumen,
                (
                    SELECT MIN(kp.id_kasbon) FROM kasbon_pembayaran kp
                    WHERE kp.id_pemasukan = pm.id_pemasukan AND kp.dihapus_pada IS NULL
                ) as id_kasbon
            ");
    }
}
