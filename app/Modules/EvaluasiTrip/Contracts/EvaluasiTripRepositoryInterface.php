<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip\Contracts;

use App\Modules\EvaluasiTrip\EvaluasiTripModel;

interface EvaluasiTripRepositoryInterface
{
    public function findByPenugasan(string $idPenugasan): ?EvaluasiTripModel;
    public function existsByPenugasan(string $idPenugasan): bool;
    public function findById(string $id): ?EvaluasiTripModel;
    public function create(array $data): EvaluasiTripModel;
    public function update(EvaluasiTripModel $model, array $data): EvaluasiTripModel;
}
