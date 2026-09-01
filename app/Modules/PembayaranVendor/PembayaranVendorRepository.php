<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor;

use App\Modules\PembayaranVendor\Contracts\PembayaranVendorRepositoryInterface;
use Illuminate\Support\Facades\DB;

class PembayaranVendorRepository implements PembayaranVendorRepositoryInterface
{
    public function findInvoiceUntukPerusahaan(string $idInvoice, string $idPerusahaan): ?object
    {
        return DB::table('invoice_vendor')
            ->where('id_invoice_vendor', $idInvoice)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_invoice_vendor', 'status', 'total']);
    }

    public function listByInvoice(string $idInvoice): array
    {
        return PembayaranVendorModel::active()
            ->where('id_invoice_vendor', $idInvoice)
            ->orderBy('tanggal_bayar')
            ->orderBy('dibuat_pada')
            ->get()
            ->all();
    }

    public function findByIdUntukInvoice(string $id, string $idInvoice, string $idPerusahaan): ?PembayaranVendorModel
    {
        return PembayaranVendorModel::active()
            ->join('invoice_vendor', 'invoice_vendor.id_invoice_vendor', '=', 'pembayaran_vendor.id_invoice_vendor')
            ->where('pembayaran_vendor.id_pembayaran_vendor', $id)
            ->where('pembayaran_vendor.id_invoice_vendor', $idInvoice)
            ->where('invoice_vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('invoice_vendor.dihapus_pada')
            ->select('pembayaran_vendor.*')
            ->first();
    }

    public function kunciInvoice(string $idInvoice): ?object
    {
        return DB::table('invoice_vendor')
            ->where('id_invoice_vendor', $idInvoice)
            ->whereNull('dihapus_pada')
            ->lockForUpdate()
            ->first(['id_invoice_vendor', 'status', 'total']);
    }

    public function totalDibayar(string $idInvoice): float
    {
        return (float) DB::table('pembayaran_vendor')
            ->where('id_invoice_vendor', $idInvoice)
            ->whereNull('dihapus_pada')
            ->sum('nominal');
    }

    public function create(array $data): PembayaranVendorModel
    {
        return PembayaranVendorModel::create($data);
    }

    public function delete(PembayaranVendorModel $model): void
    {
        $model->softDelete();
    }

    public function recalcStatusPembayaran(string $idInvoice): void
    {
        $invoice = DB::table('invoice_vendor')
            ->where('id_invoice_vendor', $idInvoice)
            ->whereNull('dihapus_pada')
            ->first(['total']);

        if ($invoice === null) {
            return;
        }

        $dibayar = $this->totalDibayar($idInvoice);
        $total = (float) $invoice->total;

        $status = match (true) {
            $dibayar <= 0 => 'belum',
            round($dibayar, 2) >= round($total, 2) => 'lunas',
            default => 'sebagian',
        };

        DB::table('invoice_vendor')
            ->where('id_invoice_vendor', $idInvoice)
            ->update(['status_pembayaran' => $status, 'diubah_pada' => now()]);
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    public function dataCetak(string $id, string $idInvoice, string $idPerusahaan): ?object
    {
        return DB::table('pembayaran_vendor as pv')
            ->join('invoice_vendor as iv', 'iv.id_invoice_vendor', '=', 'pv.id_invoice_vendor')
            ->leftJoin('vendor as v', 'v.id_vendor', '=', 'iv.id_vendor')
            ->leftJoin('kontrak_vendor as kv', 'kv.id_kontrak_vendor', '=', 'iv.id_kontrak_vendor')
            ->where('pv.id_pembayaran_vendor', $id)
            ->where('pv.id_invoice_vendor', $idInvoice)
            ->where('iv.id_perusahaan', $idPerusahaan)
            ->whereNull('pv.dihapus_pada')
            ->whereNull('iv.dihapus_pada')
            ->select([
                'pv.id_pembayaran_vendor', 'pv.tanggal_bayar', 'pv.nominal', 'pv.metode',
                'pv.bank_pengirim', 'pv.no_referensi', 'pv.catatan',
                'iv.nomor_invoice', 'iv.total as total_invoice',
                'v.nama_vendor',
                'kv.nomor_kontrak',
            ])
            ->first();
    }
}
