<?php

declare(strict_types=1);

namespace App\Modules\PenagihanTrip;

use App\Modules\PenagihanTrip\Contracts\PenagihanTripRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class PenagihanTripRepository implements PenagihanTripRepositoryInterface
{
    public function proyekInfo(string $idProyek, string $idPerusahaan): ?object
    {
        return DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->first(['id_proyek', 'id_klien', 'nama_proyek']);
    }

    public function tripSiapTagih(string $idPerusahaan, string $idProyek, ?string $dari, ?string $sampai, bool $lock = false): array
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
            ->where('p.id_proyek', $idProyek)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->whereNull('lp.dihapus_pada')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('faktur_trip as ft')
                    ->join('faktur as f', 'f.id_faktur', '=', 'ft.id_faktur')
                    ->whereColumn('ft.id_trip', 't.id_trip')
                    ->whereNull('ft.dihapus_pada')
                    ->whereNull('f.dihapus_pada')
                    ->where('f.status', '!=', 'batal');
            })
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
                'pr.id_klien',
                'pr.id_proyek',
                'pr.tipe_harga',
            ])
            ->get();

        $this->isiArmadaAlokasi($rows);

        return $rows->all();
    }

    /**
     * Trip supir shift: penugasan tanpa id_armada — armada hariannya dari
     * alokasi_armada (konsisten TripRepository::attachJadwalDetail).
     * Trip sumber vendor dikecualikan: unit_only juga ber-id_armada null padahal
     * armadanya armada vendor — nopol/jenis harus dari armada_vendor, bukan alokasi.
     */
    private function isiArmadaAlokasi(\Illuminate\Support\Collection $rows): void
    {
        $butuh = $rows->filter(fn ($r) => $r->id_armada === null && $r->id_supir !== null && ($r->sumber ?? 'internal') !== 'vendor');
        if ($butuh->isEmpty()) {
            return;
        }

        $alokasi = DB::table('alokasi_armada as aa')
            ->join('armada as arm', 'arm.id_armada', '=', 'aa.id_armada')
            ->whereNull('aa.dihapus_pada')
            ->whereIn('aa.id_supir', $butuh->pluck('id_supir')->unique()->values())
            ->select('aa.id_supir', 'aa.tanggal', 'arm.nopol', 'arm.id_jenis_kendaraan')
            ->get()
            ->keyBy(fn ($row) => $row->id_supir . '|' . substr((string) $row->tanggal, 0, 10));

        foreach ($butuh as $row) {
            $cocok = $alokasi->get($row->id_supir . '|' . substr((string) $row->tanggal, 0, 10));
            if ($cocok !== null) {
                $row->nopol_internal      = $row->nopol_internal ?? $cocok->nopol;
                $row->id_jenis_kendaraan  = $row->id_jenis_kendaraan ?? $cocok->id_jenis_kendaraan;
            }
        }
    }

    public function insertFakturTrip(string $idFaktur, string $idTrip): void
    {
        DB::table('faktur_trip')->insert(RecordHelper::stampCreate([
            'id_faktur' => $idFaktur,
            'id_trip'   => $idTrip,
        ], 'id_faktur_trip'));
    }

    public function biayaTagihanUntukTrips(array $idTrips): array
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
}
