<?php

declare(strict_types=1);

namespace App\Modules\LaporanPengeluaran;

use App\Modules\LaporanPengeluaran\Contracts\LaporanPengeluaranRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LaporanPengeluaranRepository implements LaporanPengeluaranRepositoryInterface
{
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
            ->groupBy('x.sumber_dana', 'x.kategori', 'x.sub_kategori')
            ->selectRaw('x.sumber_dana, x.kategori, x.sub_kategori, COUNT(*) as jumlah, SUM(x.nominal) as total')
            ->get();
    }

    private function tersaring(string $idPerusahaan, string $dari, string $sampai, array $filter): Builder
    {
        $query = DB::query()->fromSub($this->gabungan($idPerusahaan, $dari, $sampai), 'x');

        $sumberDana = $filter['sumber_dana'] ?? null;
        if ($sumberDana !== null && $sumberDana !== '') {
            $query->where('x.sumber_dana', $sumberDana);
        }

        $kategori = $filter['kategori'] ?? null;
        if ($kategori !== null && $kategori !== '') {
            $query->where('x.kategori', $kategori);
        }

        $subKategori = $filter['sub_kategori'] ?? null;
        if ($subKategori !== null && $subKategori !== '') {
            $query->where('x.sub_kategori', $subKategori);
        }

        $search = $filter['search'] ?? null;
        if ($search !== null && $search !== '') {
            $pola = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $query->where(function ($q) use ($pola) {
                $q->whereRaw("x.nomor LIKE ? ESCAPE '!'", [$pola])
                    ->orWhereRaw("x.penerima LIKE ? ESCAPE '!'", [$pola])
                    ->orWhereRaw("x.keterangan LIKE ? ESCAPE '!'", [$pola]);
            });
        }

        return $query;
    }

    private function gabungan(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return $this->queryPengajuan($idPerusahaan, $dari, $sampai)
            ->unionAll($this->queryPembayaranVendor($idPerusahaan, $dari, $sampai))
            ->unionAll($this->queryKasKecil($idPerusahaan, $dari, $sampai));
    }

    private function queryPengajuan(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('pengajuan_pengeluaran as pp')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'pp.ditransfer_oleh')
            ->leftJoin('perawatan_armada as pa', 'pa.id_perawatan', '=', 'pp.id_perawatan')
            ->whereNull('pp.dihapus_pada')
            ->whereNull('pp.id_invoice_vendor')
            ->where('pp.id_perusahaan', $idPerusahaan)
            ->where('pp.status', 'ditransfer')
            ->whereBetween('pp.tanggal_transfer', [$dari, $sampai])
            ->selectRaw("
                pp.id_pengajuan as id_baris,
                pp.tanggal_transfer as tanggal,
                'rekening' as sumber_dana,
                pp.kategori as kategori,
                NULL as sub_kategori,
                pp.nomor_pengajuan as nomor,
                pp.penerima as penerima,
                pp.keterangan as keterangan,
                pp.nominal as nominal,
                pp.url_bukti as url_bukti,
                u.username as oleh,
                CASE
                    WHEN pp.id_uang_jalan IS NOT NULL THEN 'uang_jalan'
                    WHEN pp.id_kasbon IS NOT NULL THEN 'kasbon'
                    WHEN pp.id_pengisian_kas_kecil IS NOT NULL THEN 'pengisian_kas_kecil'
                    WHEN pp.id_permintaan_pembelian IS NOT NULL THEN 'permintaan_pembelian'
                    WHEN pp.id_pembelian IS NOT NULL THEN 'pembelian_sparepart'
                    WHEN pp.id_perawatan IS NOT NULL THEN 'perawatan'
                    WHEN pp.id_periode IS NOT NULL THEN 'payroll'
                    ELSE 'pengajuan'
                END as tipe_dokumen,
                COALESCE(
                    pp.id_uang_jalan, pp.id_kasbon, pp.id_pengisian_kas_kecil, pp.id_permintaan_pembelian,
                    pp.id_pembelian, pp.id_perawatan, pp.id_periode, pp.id_pengajuan
                ) as id_dokumen,
                pa.id_armada as id_pendukung
            ");
    }

    private function queryPembayaranVendor(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('pembayaran_vendor as pv')
            ->join('invoice_vendor as iv', 'iv.id_invoice_vendor', '=', 'pv.id_invoice_vendor')
            ->leftJoin('vendor as v', 'v.id_vendor', '=', 'iv.id_vendor')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'pv.dibuat_oleh')
            ->whereNull('pv.dihapus_pada')
            ->whereNull('iv.dihapus_pada')
            ->where('iv.id_perusahaan', $idPerusahaan)
            ->whereBetween('pv.tanggal_bayar', [$dari, $sampai])
            ->selectRaw("
                pv.id_pembayaran_vendor as id_baris,
                pv.tanggal_bayar as tanggal,
                'rekening' as sumber_dana,
                'pembayaran_vendor' as kategori,
                NULL as sub_kategori,
                iv.nomor_invoice as nomor,
                v.nama_vendor as penerima,
                pv.catatan as keterangan,
                pv.nominal as nominal,
                pv.url_bukti as url_bukti,
                u.username as oleh,
                'invoice_vendor' as tipe_dokumen,
                iv.id_invoice_vendor as id_dokumen,
                NULL as id_pendukung
            ");
    }

    private function queryKasKecil(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('kas_kecil_transaksi as t')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 't.dibuat_oleh')
            ->whereNull('t.dihapus_pada')
            ->where('t.id_perusahaan', $idPerusahaan)
            ->where('t.arah', 'keluar')
            ->whereBetween('t.tanggal', [$dari, $sampai])
            ->selectRaw("
                t.id_transaksi as id_baris,
                t.tanggal as tanggal,
                'kas_kecil' as sumber_dana,
                'kas_kecil' as kategori,
                t.kategori as sub_kategori,
                t.nomor_transaksi as nomor,
                t.penerima as penerima,
                t.keterangan as keterangan,
                t.nominal as nominal,
                t.url_bukti as url_bukti,
                u.username as oleh,
                'kas_kecil' as tipe_dokumen,
                t.id_transaksi as id_dokumen,
                NULL as id_pendukung
            ");
    }
}
