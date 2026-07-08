<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TripRepository implements TripRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return TripModel::active()
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
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
        // Trace trip -> jadwal_keberangkatan -> penugasan -> proyek
        $row = DB::table('trip')
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->where('trip.id_trip', $idTrip)
            ->whereNull('trip.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->select('p.id_proyek')
            ->first();

        if (! $row) {
            return [
                'total_bbm'         => 0,
                'total_uang_jalan'  => 0,
                'total_biaya_lain'  => 0,
                'total_keseluruhan' => 0,
                'items'             => [],
            ];
        }

        $idProyek = $row->id_proyek;

        // Aggregate faktur items linked to this proyek
        $items = DB::table('faktur_item as fi')
            ->join('faktur as f', 'fi.id_faktur', '=', 'f.id_faktur')
            ->where('f.id_proyek', $idProyek)
            ->whereNull('f.dihapus_pada')
            ->whereNull('fi.dihapus_pada')
            ->select(
                'fi.id_faktur_item',
                'fi.deskripsi',
                'fi.qty',
                'fi.harga_satuan',
                'fi.subtotal',
                'f.nomor_faktur'
            )
            ->orderBy('f.tanggal_faktur')
            ->orderBy('fi.dibuat_pada')
            ->get();

        return [
            'total_bbm'         => 0,
            'total_uang_jalan'  => 0,
            'total_biaya_lain'  => 0,
            'total_keseluruhan' => (float) $items->sum('subtotal'),
            'items'             => $items->values()->toArray(),
        ];
    }
}
