<?php

declare(strict_types=1);

namespace App\Modules\Trip\Contracts;

use App\Modules\Trip\TripModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface TripRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function exists(string $idTrip): bool;
    public function findById(string $id): ?TripModel;
    public function findByJadwal(string $idJadwal): ?TripModel;
    public function create(array $data): TripModel;
    public function update(TripModel $model, array $data): TripModel;
    public function delete(TripModel $model): void;
    public function rekapBiaya(string $idTrip): array;
}
