<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Trip\Contracts\TripRepositoryInterface;

class TripService
{
    public function __construct(private readonly TripRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idJadwal = null): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $idJadwal);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): TripModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Trip tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): TripModel
    {
        $idJadwal = $data['id_jadwal'];

        $existing = $this->repo->findByJadwal($idJadwal);
        if ($existing !== null) {
            abort(409, 'Trip untuk jadwal ini sudah ada');
        }

        return $this->repo->create($data);
    }

    public function checkin(string $id): TripModel
    {
        $trip = $this->findOrFail($id);

        if ($trip->status !== 'belum_mulai') {
            abort(422, 'Trip tidak dapat di-checkin pada status saat ini');
        }

        return $this->repo->update($trip, [
            'waktu_checkin' => now(),
            'status'        => 'berjalan',
        ]);
    }

    public function checkout(string $id): TripModel
    {
        $trip = $this->findOrFail($id);

        if ($trip->status !== 'berjalan') {
            abort(422, 'Trip tidak dapat di-checkout pada status saat ini');
        }

        return $this->repo->update($trip, [
            'waktu_checkout' => now(),
            'status'         => 'selesai',
        ]);
    }

    public function update(string $id, array $data): TripModel
    {
        $trip = $this->findOrFail($id);
        return $this->repo->update($trip, $data);
    }

    public function batalkan(string $id, string $idPerusahaan): TripModel
    {
        $trip = $this->findOrFail($id);

        if (!$this->repo->milikPerusahaan($id, $idPerusahaan)) {
            abort(404, 'Trip tidak ditemukan');
        }

        if ($trip->status === 'selesai') {
            abort(422, 'Trip yang sudah selesai tidak dapat dibatalkan');
        }

        return $this->repo->update($trip, [
            'status' => 'dibatalkan',
        ]);
    }

    public function delete(string $id): void
    {
        $trip = $this->findOrFail($id);
        $this->repo->delete($trip);
    }

    public function rekapBiaya(string $id, string $idPerusahaan): array
    {
        $this->findOrFail($id); // ensures 404 if trip doesn't exist

        if (!$this->repo->milikPerusahaan($id, $idPerusahaan)) {
            abort(404, 'Trip tidak ditemukan');
        }

        return $this->repo->rekapBiaya($id);
    }
}
