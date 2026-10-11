<?php

declare(strict_types=1);

namespace App\Modules\RekapPajak;

use App\Modules\RekapPajak\Contracts\RekapPajakRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RekapPajakRepository implements RekapPajakRepositoryInterface
{
    private const TANGGAL_FAKTUR = 'COALESCE(f.tanggal_faktur, DATE(f.dibuat_pada))';

    private const STATUS_FAKTUR_SAH     = ['terkirim', 'lunas'];
    private const STATUS_PR_DIBELI      = ['dibeli', 'diterima_sebagian', 'diterima', 'selesai'];
    private const STATUS_PEMBELIAN_SAH  = ['dibeli', 'lunas'];

    public function fakturKlien(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return $this->fakturSah($idPerusahaan, $dari, $sampai)
            ->leftJoin('klien as k', 'k.id_klien', '=', 'f.id_klien')
            ->selectRaw('f.id_faktur, f.nomor_faktur, f.nama_pajak, f.persen_pajak, ' . self::TANGGAL_FAKTUR . ' as tanggal, k.nama_klien')
            ->orderByRaw(self::TANGGAL_FAKTUR)
            ->orderBy('f.nomor_faktur')
            ->get();
    }

    public function dppFaktur(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return DB::table('faktur_item as fi')
            ->whereNull('fi.dihapus_pada')
            ->whereIn('fi.id_faktur', $this->fakturSah($idPerusahaan, $dari, $sampai)->select('f.id_faktur'))
            ->groupBy('fi.id_faktur')
            ->selectRaw('fi.id_faktur, SUM(fi.subtotal) as dpp')
            ->pluck('dpp', 'id_faktur');
    }

    public function pajakFaktur(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return DB::table('faktur_pajak as fp')
            ->whereNull('fp.dihapus_pada')
            ->whereIn('fp.id_faktur', $this->fakturSah($idPerusahaan, $dari, $sampai)->select('f.id_faktur'))
            ->orderBy('fp.urutan')
            ->get(['fp.id_faktur', 'fp.nama', 'fp.persen'])
            ->groupBy('id_faktur');
    }

    public function ppnInvoiceVendor(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return $this->invoiceVendorSah($idPerusahaan, $dari, $sampai)
            ->where('iv.ppn', '>', 0)
            ->get(['iv.id_invoice_vendor', 'iv.nomor_invoice', 'iv.tanggal_invoice as tanggal', 'iv.dpp', 'iv.ppn', 'v.nama_vendor', 'v.npwp']);
    }

    public function ppnPermintaanPembelian(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return DB::table('permintaan_pembelian as pr')
            ->leftJoin('supplier as s', 's.id_supplier', '=', 'pr.id_supplier')
            ->whereNull('pr.dihapus_pada')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereIn('pr.status', self::STATUS_PR_DIBELI)
            ->whereNotNull('pr.total_aktual')
            ->where('pr.ppn', '>', 0)
            ->whereBetween('pr.tanggal_pembelian', [$dari, $sampai])
            ->orderBy('pr.tanggal_pembelian')
            ->orderBy('pr.nomor_permintaan')
            ->get([
                'pr.id_permintaan', 'pr.nomor_permintaan', 'pr.nomor_po', 'pr.tanggal_pembelian as tanggal',
                'pr.total_aktual', 'pr.ppn', 'pr.ppn_persen', 'pr.ongkir', 'pr.nama_toko', 's.nama as nama_supplier',
            ]);
    }

    public function ppnPembelianSparepart(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return DB::table('pembelian_sparepart as ps')
            ->leftJoin('supplier as s', 's.id_supplier', '=', 'ps.id_supplier')
            ->whereNull('ps.dihapus_pada')
            ->whereNull('ps.id_permintaan_pembelian')
            ->where('ps.id_perusahaan', $idPerusahaan)
            ->whereIn('ps.status', self::STATUS_PEMBELIAN_SAH)
            ->whereNotNull('ps.total_aktual')
            ->where('ps.ppn', '>', 0)
            ->whereBetween('ps.tanggal_pembelian', [$dari, $sampai])
            ->orderBy('ps.tanggal_pembelian')
            ->orderBy('ps.nomor_pengajuan')
            ->get([
                'ps.id_pembelian', 'ps.nomor_pengajuan', 'ps.tanggal_pembelian as tanggal',
                'ps.total_aktual', 'ps.ppn', 'ps.ppn_persen', 'ps.ongkir', 's.nama as nama_supplier',
            ]);
    }

    public function pphInvoiceVendor(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return $this->invoiceVendorSah($idPerusahaan, $dari, $sampai)
            ->where('iv.pph', '>', 0)
            ->get(['iv.id_invoice_vendor', 'iv.nomor_invoice', 'iv.tanggal_invoice as tanggal', 'iv.dpp', 'iv.pph', 'v.nama_vendor', 'v.npwp']);
    }

    public function pph21(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return DB::table('payroll_slip as sl')
            ->join('payroll_periode as p', 'p.id_periode', '=', 'sl.id_periode')
            ->leftJoin('karyawan as k', 'k.id_karyawan', '=', 'sl.id_karyawan')
            ->whereNull('sl.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.status', 'final')
            ->where('sl.pph21', '>', 0)
            ->whereBetween('p.tanggal_selesai', [$dari, $sampai])
            ->orderBy('p.tanggal_selesai')
            ->orderBy('k.nama_karyawan')
            ->get([
                'p.id_periode', 'p.nama as nama_periode', 'p.tanggal_selesai as tanggal',
                'k.nama_karyawan', 'k.nik_ktp', 'k.npwp', 'k.status_ptkp', 'sl.total_bruto', 'sl.pph21',
            ]);
    }

    public function potonganKlien(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        return DB::table('pembayaran_faktur as pf')
            ->join('faktur as f', 'f.id_faktur', '=', 'pf.id_faktur')
            ->leftJoin('klien as k', 'k.id_klien', '=', 'f.id_klien')
            ->whereNull('pf.dihapus_pada')
            ->whereNull('f.dihapus_pada')
            ->where('f.id_perusahaan', $idPerusahaan)
            ->where('f.status', '!=', 'batal')
            ->where('pf.potongan', '>', 0)
            ->whereBetween('pf.tanggal_bayar', [$dari, $sampai])
            ->orderBy('pf.tanggal_bayar')
            ->orderBy('f.nomor_faktur')
            ->get([
                'f.id_faktur', 'f.nomor_faktur', 'pf.tanggal_bayar as tanggal', 'pf.nominal',
                'pf.potongan', 'pf.keterangan_potongan', 'k.nama_klien',
            ]);
    }

    private function fakturSah(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('faktur as f')
            ->whereNull('f.dihapus_pada')
            ->where('f.id_perusahaan', $idPerusahaan)
            ->whereIn('f.status', self::STATUS_FAKTUR_SAH)
            ->whereRaw(self::TANGGAL_FAKTUR . ' BETWEEN ? AND ?', [$dari, $sampai]);
    }

    private function invoiceVendorSah(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('invoice_vendor as iv')
            ->leftJoin('vendor as v', 'v.id_vendor', '=', 'iv.id_vendor')
            ->whereNull('iv.dihapus_pada')
            ->where('iv.id_perusahaan', $idPerusahaan)
            ->where('iv.status', 'diverifikasi')
            ->whereBetween('iv.tanggal_invoice', [$dari, $sampai])
            ->orderBy('iv.tanggal_invoice')
            ->orderBy('iv.nomor_invoice');
    }
}
