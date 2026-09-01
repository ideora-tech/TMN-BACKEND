<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor;

use App\Modules\InvoiceVendor\Contracts\InvoiceVendorRepositoryInterface;
use App\Support\RecordHelper;
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
            ->leftJoin('kontrak_vendor', function ($join) {
                $join->on('kontrak_vendor.id_kontrak_vendor', '=', 'invoice_vendor.id_kontrak_vendor')
                    ->whereNull('kontrak_vendor.dihapus_pada');
            })
            ->where('invoice_vendor.id_perusahaan', $idPerusahaan)
            ->select('invoice_vendor.*', 'vendor.nama_vendor as nama_vendor', 'kontrak_vendor.nilai_kontrak as nilai_kontrak_vendor')
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
            ->first(['id_kontrak_vendor', 'id_vendor', 'nomor_kontrak', 'termin_pembayaran_hari', 'nilai_kontrak']);
    }

    public function vendorInfo(string $idVendor): ?object
    {
        return DB::table('vendor')
            ->where('id_vendor', $idVendor)
            ->first(['id_vendor', 'nama_vendor']);
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
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

    public function tripSiapTagih(string $idPerusahaan, string $idKontrakVendor, ?string $dari, ?string $sampai, ?string $idProyek = null, bool $lock = false): array
    {
        $rows = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
            ->leftJoin('supir_vendor as sv', 'p.id_supir_vendor', '=', 'sv.id_supir_vendor')
            ->leftJoin('rute as r', 'jk.id_rute', '=', 'r.id_rute')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->where('p.id_kontrak_vendor', $idKontrakVendor)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('invoice_vendor_trip as ivt')
                    ->join('invoice_vendor as iv', 'iv.id_invoice_vendor', '=', 'ivt.id_invoice_vendor')
                    ->whereColumn('ivt.id_trip', 't.id_trip')
                    ->whereNull('ivt.dihapus_pada')
                    ->whereNull('iv.dihapus_pada');
            })
            ->when($idProyek, fn ($q, $v) => $q->where('p.id_proyek', $v))
            ->when($dari, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) >= ?', [$v]))
            ->when($sampai, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) <= ?', [$v]))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderByRaw('COALESCE(jk.waktu_berangkat, t.dibuat_pada)')
            ->select([
                't.id_trip',
                DB::raw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) as tanggal'),
                'jk.id_rute',
                'r.nama_rute',
                'jk.rute as rute_teks',
                'av.nopol',
                'sv.nama as driver_nama',
                'pr.id_proyek',
                'pr.kode_proyek',
                'pr.nama_proyek',
            ])
            ->get();

        return $rows->all();
    }

    public function insertInvoiceVendorTrip(string $idInvoiceVendor, string $idTrip): void
    {
        DB::table('invoice_vendor_trip')->insert(RecordHelper::stampCreate([
            'id_invoice_vendor' => $idInvoiceVendor,
            'id_trip'           => $idTrip,
        ], 'id_invoice_vendor_trip'));
    }

    public function tripTerkaitUntukInvoice(string $idInvoiceVendor): array
    {
        return DB::table('invoice_vendor_trip as ivt')
            ->join('trip as t', 't.id_trip', '=', 'ivt.id_trip')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
            ->leftJoin('supir_vendor as sv', 'p.id_supir_vendor', '=', 'sv.id_supir_vendor')
            ->leftJoin('rute as r', 'jk.id_rute', '=', 'r.id_rute')
            ->where('ivt.id_invoice_vendor', $idInvoiceVendor)
            ->whereNull('ivt.dihapus_pada')
            ->orderByRaw('COALESCE(jk.waktu_berangkat, t.dibuat_pada)')
            ->select([
                't.id_trip',
                DB::raw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) as tanggal'),
                'r.nama_rute',
                'jk.rute as rute_teks',
                'av.nopol',
                'sv.nama as driver_nama',
                'pr.kode_proyek',
                'pr.nama_proyek',
                't.status',
            ])
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
