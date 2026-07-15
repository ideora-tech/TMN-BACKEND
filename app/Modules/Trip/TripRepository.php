<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TripRepository implements TripRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit, ?string $idJadwal = null): LengthAwarePaginator
    {
        return TripModel::active()
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->when($idJadwal, fn ($q, $v) => $q->where('trip.id_jadwal', $v))
            ->select('trip.*')
            ->orderBy('trip.dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function exists(string $idTrip): bool
    {
        return TripModel::active()->where('id_trip', $idTrip)->exists();
    }

    public function findById(string $id): ?TripModel
    {
        return TripModel::active()->find($id);
    }

    public function findByJadwal(string $idJadwal): ?TripModel
    {
        return TripModel::active()->where('id_jadwal', $idJadwal)->first();
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
