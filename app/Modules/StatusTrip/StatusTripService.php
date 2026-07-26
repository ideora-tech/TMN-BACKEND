<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Modules\StatusTrip\Contracts\StatusTripRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Collection;

class StatusTripService
{
    public function __construct(
        private readonly StatusTripRepositoryInterface $repo,
        private readonly TripRepositoryInterface $tripRepo,
    ) {}

    public function listByTrip(string $idTrip, ?string $idPerusahaan = null): Collection
    {
        $this->ensureTripExists($idTrip, $idPerusahaan);
        return $this->repo->listByTrip($idTrip);
    }

    public function create(string $idTrip, array $data, ?string $idPerusahaan = null): object
    {
        $this->ensureTripExists($idTrip, $idPerusahaan);

        return $this->repo->create(array_merge($data, ['id_trip' => $idTrip]));
    }

    private function ensureTripExists(string $idTrip, ?string $idPerusahaan = null): void
    {
        if (!$this->tripRepo->exists($idTrip)) {
            abort(404, 'Trip tidak ditemukan');
        }
        if ($idPerusahaan !== null && !$this->tripRepo->milikPerusahaan($idTrip, $idPerusahaan)) {
            abort(404, 'Trip tidak ditemukan');
        }
    }
}
