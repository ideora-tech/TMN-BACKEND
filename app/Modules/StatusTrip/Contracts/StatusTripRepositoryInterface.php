<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip\Contracts;

use App\Modules\StatusTrip\StatusTripModel;
use Illuminate\Support\Collection;

interface StatusTripRepositoryInterface
{
    public function listByTrip(string $idTrip): Collection;
    public function create(array $data): StatusTripModel;
}
