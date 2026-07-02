<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Modules\EvaluasiTrip\Contracts\EvaluasiTripRepositoryInterface;

class EvaluasiTripRepository implements EvaluasiTripRepositoryInterface
{
    public function findByPenugasan(string $idPenugasan): ?EvaluasiTripModel
    {
        return EvaluasiTripModel::active()->where('id_penugasan', $idPenugasan)->first();
    }

    public function existsByPenugasan(string $idPenugasan): bool
    {
        return EvaluasiTripModel::active()->where('id_penugasan', $idPenugasan)->exists();
    }

    public function findById(string $id): ?EvaluasiTripModel
    {
        return EvaluasiTripModel::active()->find($id);
    }

    public function create(array $data): EvaluasiTripModel
    {
        return EvaluasiTripModel::create($data);
    }

    public function update(EvaluasiTripModel $model, array $data): EvaluasiTripModel
    {
        $model->update($data);
        return $model->fresh();
    }
}
