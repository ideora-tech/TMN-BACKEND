<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Modules\EvaluasiTrip\Contracts\EvaluasiTripRepositoryInterface;

class EvaluasiTripService
{
    public function __construct(private readonly EvaluasiTripRepositoryInterface $repo) {}

    public function getByPenugasan(string $idPenugasan): EvaluasiTripModel
    {
        $record = $this->repo->findByPenugasan($idPenugasan);
        if ($record === null) {
            abort(404, 'Evaluasi trip tidak ditemukan');
        }
        return $record;
    }

    public function findOrFail(string $id): EvaluasiTripModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Evaluasi trip tidak ditemukan');
        }
        return $record;
    }

    public function create(string $idPenugasan, array $data): EvaluasiTripModel
    {
        if ($this->repo->existsByPenugasan($idPenugasan)) {
            abort(409, 'Evaluasi untuk penugasan ini sudah ada');
        }

        return $this->repo->create(array_merge($data, [
            'id_penugasan'       => $idPenugasan,
            'id_dievaluasi_oleh' => auth()->id(),
        ]));
    }

    public function update(string $id, array $data): EvaluasiTripModel
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }
}
