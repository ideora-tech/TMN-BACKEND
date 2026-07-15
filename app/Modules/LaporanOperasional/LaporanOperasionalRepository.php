<?php

declare(strict_types=1);

namespace App\Modules\LaporanOperasional;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Karyawan\KaryawanModel;
use App\Modules\LaporanOperasional\Contracts\LaporanOperasionalRepositoryInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LaporanOperasionalRepository implements LaporanOperasionalRepositoryInterface
{
    /**
     * Join dasar trip -> jadwal_keberangkatan -> penugasan -> proyek (+klien/armada/supir/laporan)
     * yang dipakai bersama oleh queryTrip() dan ringkasanTrip().
     */
    private function baseTripQuery(string $idPerusahaan, array $f): Builder
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('klien as k', 'pr.id_klien', '=', 'k.id_klien')
            ->leftJoin('armada as a', 'p.id_armada', '=', 'a.id_armada')
            ->leftJoin('supir as s', 'p.id_supir', '=', 's.id_supir')
            ->leftJoin('armada_vendor as av', function ($j) {
                $j->on('av.id_armada_vendor', '=', 'p.id_armada_vendor')->whereNull('av.dihapus_pada');
            })
            ->leftJoin('supir_vendor as sv', function ($j) {
                $j->on('sv.id_supir_vendor', '=', 'p.id_supir_vendor')->whereNull('sv.dihapus_pada');
            })
            ->leftJoin('laporan_perjalanan as lp', function ($j) {
                $j->on('lp.id_trip', '=', 't.id_trip')->whereNull('lp.dihapus_pada');
            })
            ->leftJoin(DB::raw('(select id_laporan, sum(nominal) as total_lain from biaya_lain_trip where dihapus_pada is null group by id_laporan) bl'),
                'bl.id_laporan', '=', 'lp.id_laporan')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('t.dihapus_pada')->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')->whereNull('pr.dihapus_pada')
            ->when($f['dari'] ?? null,      fn ($q, $v) => $q->whereDate('jk.waktu_berangkat', '>=', $v))
            ->when($f['sampai'] ?? null,    fn ($q, $v) => $q->whereDate('jk.waktu_berangkat', '<=', $v))
            ->when($f['id_klien'] ?? null,  fn ($q, $v) => $q->where('pr.id_klien', $v))
            ->when($f['id_supir'] ?? null,  fn ($q, $v) => $q->where('p.id_supir', $v))
            ->when($f['id_armada'] ?? null, fn ($q, $v) => $q->where('p.id_armada', $v))
            ->when($f['sumber'] ?? null,    fn ($q, $v) => $q->where('p.sumber', $v));
    }

    public function queryTrip(string $idPerusahaan, array $filter): Builder
    {
        return $this->baseTripQuery($idPerusahaan, $filter)
            ->select('t.id_trip', 'jk.waktu_berangkat', 'pr.nama_proyek', 'k.nama_klien',
                DB::raw('coalesce(a.nopol, av.nopol) as nopol'),
                DB::raw('coalesce(s.nama, sv.nama) as nama_supir'),
                'p.sumber', 't.status', 'lp.jarak_tempuh_km',
                DB::raw('coalesce(lp.biaya_bbm,0) + coalesce(lp.uang_jalan,0) + coalesce(bl.total_lain,0) as total_biaya'))
            ->orderBy('jk.waktu_berangkat', 'desc');
    }

    public function ringkasanTrip(string $idPerusahaan, array $filter): array
    {
        $row = $this->baseTripQuery($idPerusahaan, $filter)
            ->selectRaw('count(t.id_trip) as jumlah_trip, coalesce(sum(coalesce(lp.biaya_bbm,0) + coalesce(lp.uang_jalan,0) + coalesce(bl.total_lain,0)),0) as total_biaya')
            ->first();

        return [
            'jumlah_trip' => (int) ($row->jumlah_trip ?? 0),
            'total_biaya' => (float) ($row->total_biaya ?? 0),
        ];
    }

    public function karyawanAktif(string $idPerusahaan): EloquentCollection
    {
        return KaryawanModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_karyawan')
            ->get();
    }

    public function armadaAktif(string $idPerusahaan): EloquentCollection
    {
        return ArmadaModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nopol')
            ->get();
    }
}
