<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TripRepository implements TripRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit, ?string $idJadwal = null, ?string $idPenugasan = null, ?string $idSupir = null): LengthAwarePaginator
    {
        $paginator = TripModel::active()
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->when($idJadwal, fn ($q, $v) => $q->where('trip.id_jadwal', $v))
            ->when($idPenugasan, fn ($q, $v) => $q->where('jk.id_penugasan', $v))
            ->when($idSupir, fn ($q, $v) => $q->where('p.id_supir', $v))
            ->select('trip.*')
            ->orderBy('trip.dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachJadwalDetail($paginator->getCollection());

        return $paginator;
    }

    public function exists(string $idTrip): bool
    {
        return TripModel::active()->where('id_trip', $idTrip)->exists();
    }

    public function findById(string $id): ?TripModel
    {
        $record = TripModel::active()->find($id);

        if ($record !== null) {
            $this->attachJadwalDetail(collect([$record]));
        }

        return $record;
    }

    /**
     * Attach rute/waktu_berangkat/supir_nama/armada_nopol via setRelation() —
     * never raw property assignment, karena instance ini bisa mengalir ke
     * repo->update() (lihat TripService::checkin/checkout/update/batalkan)
     * dan raw assignment akan ikut ter-UPDATE sebagai kolom SQL yang tidak ada.
     */
    private function attachJadwalDetail(Collection $records): void
    {
        $idJadwalList = $records->pluck('id_jadwal')->unique()->filter()->values();
        if ($idJadwalList->isEmpty()) {
            return;
        }

        $jadwalRows = DB::table('jadwal_keberangkatan')
            ->whereIn('id_jadwal', $idJadwalList)
            ->select('id_jadwal', 'id_penugasan', 'id_rute', 'waktu_berangkat', 'rute')
            ->get()
            ->keyBy('id_jadwal');

        $idRuteList      = $jadwalRows->pluck('id_rute')->unique()->filter()->values();
        $idPenugasanList = $jadwalRows->pluck('id_penugasan')->unique()->filter()->values();

        $ruteMap = $idRuteList->isEmpty() ? collect()
            : DB::table('rute')->whereIn('id_rute', $idRuteList)->pluck('nama_rute', 'id_rute');

        $penugasanRows = $idPenugasanList->isEmpty() ? collect() : DB::table('penugasan')
            ->whereIn('id_penugasan', $idPenugasanList)
            ->select('id_penugasan', 'id_armada', 'id_supir', 'id_armada_vendor', 'id_supir_vendor')
            ->get()
            ->keyBy('id_penugasan');

        $idArmadaList       = $penugasanRows->pluck('id_armada')->unique()->filter()->values();
        $idSupirList        = $penugasanRows->pluck('id_supir')->unique()->filter()->values();
        $idArmadaVendorList = $penugasanRows->pluck('id_armada_vendor')->unique()->filter()->values();
        $idSupirVendorList  = $penugasanRows->pluck('id_supir_vendor')->unique()->filter()->values();

        $armadaMap = $idArmadaList->isEmpty() ? collect()
            : DB::table('armada')->whereIn('id_armada', $idArmadaList)->pluck('nopol', 'id_armada');
        $supirMap = $idSupirList->isEmpty() ? collect()
            : DB::table('supir')->whereIn('id_supir', $idSupirList)->pluck('nama', 'id_supir');
        $armadaVendorMap = $idArmadaVendorList->isEmpty() ? collect()
            : DB::table('armada_vendor')->whereIn('id_armada_vendor', $idArmadaVendorList)->pluck('nopol', 'id_armada_vendor');
        $supirVendorMap = $idSupirVendorList->isEmpty() ? collect()
            : DB::table('supir_vendor')->whereIn('id_supir_vendor', $idSupirVendorList)->pluck('nama', 'id_supir_vendor');

        foreach ($records as $record) {
            $jadwal    = $jadwalRows->get($record->id_jadwal);
            $penugasan = $jadwal !== null ? $penugasanRows->get($jadwal->id_penugasan) : null;

            $armadaNopol = $penugasan !== null
                ? ($armadaMap->get($penugasan->id_armada) ?? $armadaVendorMap->get($penugasan->id_armada_vendor))
                : null;
            $supirNama = $penugasan !== null
                ? ($supirMap->get($penugasan->id_supir) ?? $supirVendorMap->get($penugasan->id_supir_vendor))
                : null;

            $ruteNama = $jadwal !== null
                ? ($ruteMap->get($jadwal->id_rute) ?? $jadwal->rute)
                : null;

            $record->setRelation('rute', $ruteNama);
            $record->setRelation('waktu_berangkat', $jadwal->waktu_berangkat ?? null);
            $record->setRelation('armada_nopol', $armadaNopol);
            $record->setRelation('supir_nama', $supirNama);
        }
    }

    public function findByJadwal(string $idJadwal): ?TripModel
    {
        return TripModel::active()->where('id_jadwal', $idJadwal)->first();
    }

    public function findPenugasanMilikPerusahaan(string $idPenugasan, string $idPerusahaan): ?object
    {
        return DB::table('penugasan as p')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->where('p.id_penugasan', $idPenugasan)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->select('p.*')
            ->first();
    }

    public function adaTripAktifUntukAktor(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor): bool
    {
        if (!$idArmada && !$idSupir && !$idArmadaVendor && !$idSupirVendor) {
            return false;
        }

        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNotIn('t.status', ['selesai', 'dibatalkan'])
            ->where(function ($q) use ($idArmada, $idSupir, $idArmadaVendor, $idSupirVendor) {
                if ($idArmada) {
                    $q->orWhere('p.id_armada', $idArmada);
                }
                if ($idSupir) {
                    $q->orWhere('p.id_supir', $idSupir);
                }
                if ($idArmadaVendor) {
                    $q->orWhere('p.id_armada_vendor', $idArmadaVendor);
                }
                if ($idSupirVendor) {
                    $q->orWhere('p.id_supir_vendor', $idSupirVendor);
                }
            })
            ->lockForUpdate()
            ->exists();
    }

    public function create(array $data): TripModel
    {
        return TripModel::create($data);
    }

    public function update(TripModel $model, array $data): TripModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(TripModel $model): void
    {
        $model->softDelete();
    }

    public function rekapBiaya(string $idTrip): array
    {
        $laporan = DB::table('laporan_perjalanan')
            ->where('id_trip', $idTrip)->whereNull('dihapus_pada')->first();

        $estimasi = DB::table('trip')
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->where('trip.id_trip', $idTrip)
            ->whereNull('p.dihapus_pada')
            ->value('p.estimasi_biaya');

        $items = $laporan
            ? DB::table('biaya_lain_trip')->where('id_laporan', $laporan->id_laporan)
                ->whereNull('dihapus_pada')
                ->select('id_biaya_lain', 'nama_biaya', 'nominal')->get()
            : collect();

        $totalBbm  = (float) ($laporan->biaya_bbm ?? 0);
        $totalUj   = (float) ($laporan->uang_jalan ?? 0);
        $totalLain = (float) $items->sum('nominal');
        $total     = $totalBbm + $totalUj + $totalLain;

        return [
            'total_bbm'         => $totalBbm,
            'total_uang_jalan'  => $totalUj,
            'total_biaya_lain'  => $totalLain,
            'total_keseluruhan' => $total,
            'estimasi_biaya'    => $estimasi !== null ? (float) $estimasi : null,
            'selisih'           => $estimasi !== null ? (float) $estimasi - $total : null,
            'jarak_tempuh_km'   => $laporan && $laporan->jarak_tempuh_km !== null ? (float) $laporan->jarak_tempuh_km : null,
            'items'             => $items->map(fn ($i) => ['id_biaya_lain' => $i->id_biaya_lain, 'nama_biaya' => $i->nama_biaya, 'nominal' => (float) $i->nominal])->values()->toArray(),
        ];
    }

    public function milikPerusahaan(string $idTrip, string $idPerusahaan): bool
    {
        return DB::table('trip')
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('trip.id_trip', $idTrip)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('trip.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->exists();
    }
}
