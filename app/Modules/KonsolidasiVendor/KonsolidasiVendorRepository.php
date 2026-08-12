<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiVendor;

use App\Modules\KonsolidasiVendor\Contracts\KonsolidasiVendorRepositoryInterface;
use Illuminate\Support\Facades\DB;

class KonsolidasiVendorRepository implements KonsolidasiVendorRepositoryInterface
{
    public function vendorInfo(string $idVendor, string $idPerusahaan): ?object
    {
        return DB::table('vendor')
            ->whereNull('dihapus_pada')
            ->where('id_vendor', $idVendor)
            ->where('id_perusahaan', $idPerusahaan)
            ->first(['id_vendor', 'nama_vendor']);
    }

    public function tripVendor(string $idPerusahaan, string $idVendor, ?string $dari, ?string $sampai): array
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->join('kontrak_vendor as kv', 'p.id_kontrak_vendor', '=', 'kv.id_kontrak_vendor')
            ->join('laporan_perjalanan as lp', 'lp.id_trip', '=', 't.id_trip')
            ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
            ->leftJoin('supir as s', 'p.id_supir', '=', 's.id_supir')
            ->leftJoin('supir_vendor as sv', 'p.id_supir_vendor', '=', 'sv.id_supir_vendor')
            ->leftJoin('rute as r', 'jk.id_rute', '=', 'r.id_rute')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->where('p.sumber', 'vendor')
            ->where('kv.id_vendor', $idVendor)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->whereNull('lp.dihapus_pada')
            ->when($dari, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) >= ?', [$v]))
            ->when($sampai, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) <= ?', [$v]))
            ->orderByRaw('COALESCE(jk.waktu_berangkat, t.dibuat_pada)')
            ->select([
                't.id_trip',
                DB::raw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) as tanggal'),
                'r.nama_rute',
                'jk.rute as rute_teks',
                'av.nopol',
                's.nama as nama_supir_internal',
                'sv.nama as nama_supir_vendor',
                'lp.jarak_tempuh_km',
                'kv.id_kontrak_vendor',
                'kv.mekanisme',
            ])
            ->get()
            ->all();
    }

    public function kontrakVendor(string $idVendor): array
    {
        return DB::table('kontrak_vendor')
            ->whereNull('dihapus_pada')
            ->where('id_vendor', $idVendor)
            ->get(['id_kontrak_vendor', 'mekanisme', 'rate', 'satuan', 'nilai_kontrak'])
            ->all();
    }
}
