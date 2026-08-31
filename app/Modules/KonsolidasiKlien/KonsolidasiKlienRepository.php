<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien;

use App\Modules\KonsolidasiKlien\Contracts\KonsolidasiKlienRepositoryInterface;
use Illuminate\Support\Facades\DB;

class KonsolidasiKlienRepository implements KonsolidasiKlienRepositoryInterface
{
    public function klienInfo(string $idKlien, string $idPerusahaan): ?object
    {
        return DB::table('klien')
            ->whereNull('dihapus_pada')
            ->where('id_klien', $idKlien)
            ->where('id_perusahaan', $idPerusahaan)
            ->first(['id_klien', 'nama_klien']);
    }

    public function tripKlien(string $idPerusahaan, string $idKlien, ?string $dari, ?string $sampai, ?string $sumber = null, ?string $idProyek = null): array
    {
        $rows = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->join('laporan_perjalanan as lp', 'lp.id_trip', '=', 't.id_trip')
            ->leftJoin('armada as a', 'p.id_armada', '=', 'a.id_armada')
            ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
            ->leftJoin('supir as s', 'p.id_supir', '=', 's.id_supir')
            ->leftJoin('supir_vendor as sv', 'p.id_supir_vendor', '=', 'sv.id_supir_vendor')
            ->leftJoin('rute as r', 'jk.id_rute', '=', 'r.id_rute')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->where('pr.id_klien', $idKlien)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->whereNull('lp.dihapus_pada')
            ->when($dari, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) >= ?', [$v]))
            ->when($sampai, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) <= ?', [$v]))
            ->when($sumber, fn ($q, $v) => $q->where('p.sumber', $v))
            ->when($idProyek, fn ($q, $v) => $q->where('pr.id_proyek', $v))
            ->orderByRaw('COALESCE(jk.waktu_berangkat, t.dibuat_pada)')
            ->select([
                't.id_trip',
                'pr.id_proyek',
                DB::raw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) as tanggal'),
                'jk.id_rute',
                'r.nama_rute',
                'r.asal',
                'r.tujuan',
                'jk.rute as rute_teks',
                'p.sumber',
                'p.id_supir',
                'p.id_armada',
                'a.id_jenis_kendaraan',
                'av.id_jenis_kendaraan as id_jenis_kendaraan_vendor',
                'a.nopol as nopol_internal',
                'av.nopol as nopol_vendor',
                's.nama as nama_supir_internal',
                'sv.nama as nama_supir_vendor',
                'lp.jarak_tempuh_km',
                'pr.kode_proyek',
                'pr.nama_proyek',
                'pr.tipe_harga',
            ])
            ->selectRaw("(case when exists (
                select 1 from faktur_trip ft
                join faktur f on f.id_faktur = ft.id_faktur
                where ft.id_trip = t.id_trip
                  and ft.dihapus_pada is null
                  and f.dihapus_pada is null
                  and f.status != 'batal'
            ) then 1 else 0 end) as sudah_difakturkan")
            ->get();

        return $rows->all();
    }

    public function titikDropPerTrip(array $idTrips): array
    {
        if ($idTrips === []) {
            return [];
        }

        return DB::table('titik_drop_trip')
            ->whereIn('id_trip', $idTrips)->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_trip', 'lokasi'])
            ->groupBy('id_trip')
            ->map(fn ($g) => $g->pluck('lokasi')->all())
            ->all();
    }

    public function biayaTagihanPerTrip(array $idTrips): array
    {
        if ($idTrips === []) {
            return [];
        }

        return DB::table('biaya_tagihan_trip as bt')
            ->join('laporan_perjalanan as lp', 'lp.id_laporan', '=', 'bt.id_laporan')
            ->whereIn('lp.id_trip', $idTrips)
            ->whereNull('bt.dihapus_pada')->whereNull('lp.dihapus_pada')
            ->groupBy('lp.id_trip')
            ->selectRaw('lp.id_trip, SUM(bt.nominal) as total')
            ->pluck('total', 'id_trip')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    public function biayaTagihanDetailPerTrip(array $idTrips): array
    {
        if ($idTrips === []) {
            return [];
        }

        return DB::table('biaya_tagihan_trip as bt')
            ->join('laporan_perjalanan as lp', 'lp.id_laporan', '=', 'bt.id_laporan')
            ->whereIn('lp.id_trip', $idTrips)
            ->whereNull('bt.dihapus_pada')->whereNull('lp.dihapus_pada')
            ->orderBy('bt.dibuat_pada')
            ->get(['lp.id_trip', 'bt.nama_biaya', 'bt.nominal'])
            ->groupBy('id_trip')
            ->map(fn ($g) => $g->map(fn ($b) => ['nama_biaya' => $b->nama_biaya, 'nominal' => (float) $b->nominal])->values()->all())
            ->all();
    }

    public function uangJalanTambahanPerTrip(array $idTrips): array
    {
        if ($idTrips === []) {
            return [];
        }

        return DB::table('titik_drop_trip')
            ->whereIn('id_trip', $idTrips)
            ->whereNull('dihapus_pada')
            ->where('uang_jalan_tambahan', '>', 0)
            ->groupBy('id_trip')
            ->selectRaw('id_trip, SUM(uang_jalan_tambahan) as total')
            ->pluck('total', 'id_trip')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Nama biaya SENGAJA konstan (bukan disisipi lokasi) — KonsolidasiKlienExport
     * membuat satu kolom Excel per nama_biaya unik, jadi nama harus stabil
     * supaya beberapa titik drop tidak meledak jadi banyak kolom terpisah.
     */
    public function uangJalanTambahanDetailPerTrip(array $idTrips): array
    {
        if ($idTrips === []) {
            return [];
        }

        return DB::table('titik_drop_trip')
            ->whereIn('id_trip', $idTrips)
            ->whereNull('dihapus_pada')
            ->where('uang_jalan_tambahan', '>', 0)
            ->orderBy('urutan')
            ->get(['id_trip', 'uang_jalan_tambahan'])
            ->groupBy('id_trip')
            ->map(fn ($g) => [[
                'nama_biaya' => 'Uang Jalan Tambahan',
                'nominal'    => (float) $g->sum('uang_jalan_tambahan'),
            ]])
            ->all();
    }

    public function namaJenisKendaraanMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('jenis_kendaraan')
            ->whereIn('id_jenis_kendaraan', $ids)
            ->whereNull('dihapus_pada')
            ->pluck('nama_jenis', 'id_jenis_kendaraan')
            ->all();
    }

}
