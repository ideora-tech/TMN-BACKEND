<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor;

use App\Modules\InvoiceVendor\Contracts\InvoiceVendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InvoiceVendorRepository implements InvoiceVendorRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
        ?string $statusPembayaran = null,
        ?string $idVendor = null
    ): LengthAwarePaginator {
        $query = InvoiceVendorModel::active()
            ->leftJoin('vendor', function ($join) {
                $join->on('vendor.id_vendor', '=', 'invoice_vendor.id_vendor')
                    ->whereNull('vendor.dihapus_pada');
            })
            ->where('invoice_vendor.id_perusahaan', $idPerusahaan)
            ->select('invoice_vendor.*', 'vendor.nama_vendor as nama_vendor')
            ->orderBy('invoice_vendor.tanggal_invoice', 'desc')
            ->orderBy('invoice_vendor.dibuat_pada', 'desc');

        if ($status !== null && $status !== '') {
            $query->where('invoice_vendor.status', $status);
        }

        if ($statusPembayaran !== null && $statusPembayaran !== '') {
            $query->where('invoice_vendor.status_pembayaran', $statusPembayaran);
        }

        if ($idVendor !== null && $idVendor !== '') {
            $query->where('invoice_vendor.id_vendor', $idVendor);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_vendor.nomor_invoice', 'like', "%{$search}%")
                    ->orWhere('invoice_vendor.no_po', 'like', "%{$search}%")
                    ->orWhere('vendor.nama_vendor', 'like', "%{$search}%");
            });
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findByIdUntukPerusahaan(string $id, string $idPerusahaan): ?InvoiceVendorModel
    {
        return InvoiceVendorModel::active()
            ->where('id_invoice_vendor', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function findForUpdate(string $id): ?InvoiceVendorModel
    {
        return InvoiceVendorModel::active()->lockForUpdate()->find($id);
    }

    public function nomorSudahDipakai(string $nomor, string $idPerusahaan, ?string $kecualiId = null): bool
    {
        $query = InvoiceVendorModel::active()
            ->where('nomor_invoice', $nomor)
            ->where('id_perusahaan', $idPerusahaan);

        if ($kecualiId !== null) {
            $query->where('id_invoice_vendor', '!=', $kecualiId);
        }

        return $query->exists();
    }

    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool
    {
        return DB::table('vendor')
            ->where('id_vendor', $idVendor)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function findKontrakMilikPerusahaan(string $idKontrak, string $idPerusahaan): ?object
    {
        return DB::table('kontrak_vendor')
            ->where('id_kontrak_vendor', $idKontrak)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_kontrak_vendor', 'id_vendor', 'nomor_kontrak', 'termin_pembayaran_hari']);
    }

    public function vendorInfo(string $idVendor): ?object
    {
        return DB::table('vendor')
            ->where('id_vendor', $idVendor)
            ->first(['id_vendor', 'nama_vendor']);
    }

    public function totalDibayar(string $idInvoice): float
    {
        return (float) DB::table('pembayaran_vendor')
            ->where('id_invoice_vendor', $idInvoice)
            ->whereNull('dihapus_pada')
            ->sum('nominal');
    }

    public function daftarPembayaran(string $idInvoice): array
    {
        return DB::table('pembayaran_vendor')
            ->where('id_invoice_vendor', $idInvoice)
            ->whereNull('dihapus_pada')
            ->orderBy('tanggal_bayar')
            ->orderBy('dibuat_pada')
            ->get([
                'id_pembayaran_vendor', 'tanggal_bayar', 'nominal', 'metode',
                'bank_pengirim', 'no_referensi', 'url_bukti', 'catatan',
            ])
            ->all();
    }

    public function outstandingUntukMonitoring(string $idPerusahaan): array
    {
        return DB::table('invoice_vendor as iv')
            ->leftJoin('vendor as v', function ($join) {
                $join->on('v.id_vendor', '=', 'iv.id_vendor')
                    ->whereNull('v.dihapus_pada');
            })
            ->where('iv.id_perusahaan', $idPerusahaan)
            ->whereNull('iv.dihapus_pada')
            ->where('iv.status', 'diverifikasi')
            ->where('iv.status_pembayaran', '!=', 'lunas')
            ->orderByRaw('iv.jatuh_tempo is null')
            ->orderBy('iv.jatuh_tempo')
            ->select('iv.id_invoice_vendor', 'iv.nomor_invoice', 'v.nama_vendor as vendor_nama',
                'iv.tanggal_invoice', 'iv.jatuh_tempo', 'iv.total', 'iv.status_pembayaran')
            ->selectRaw('(select coalesce(sum(pv.nominal), 0) from pembayaran_vendor pv where pv.id_invoice_vendor = iv.id_invoice_vendor and pv.dihapus_pada is null) as dibayar')
            ->get()
            ->all();
    }

    public function create(array $data): InvoiceVendorModel
    {
        return InvoiceVendorModel::create($data);
    }

    public function update(InvoiceVendorModel $model, array $data): InvoiceVendorModel
    {
        $model->update($data);
        return $model->fresh() ?? $model;
    }

    public function delete(InvoiceVendorModel $model): void
    {
        $model->softDelete();
    }
}
