<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

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
}
