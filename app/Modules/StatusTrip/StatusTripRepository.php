<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Modules\StatusTrip\Contracts\StatusTripRepositoryInterface;
use Illuminate\Support\Collection;

class StatusTripRepository implements StatusTripRepositoryInterface
{
    public function listByTrip(string $idTrip): Collection
    {
        return StatusTripModel::where('id_trip', $idTrip)
            ->orderBy('dibuat_pada', 'desc')
            ->get();
    }

    public function create(array $data): StatusTripModel
    {
        return StatusTripModel::create($data);
    }
}
