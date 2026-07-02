<?php

declare(strict_types=1);

namespace App\Modules\Armada;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;

class ArmadaService
{
    public function __construct(private readonly ArmadaRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $status = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $status);

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

    public function findOrFail(string $id): ArmadaModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Armada tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): ArmadaModel
    {
        $existing = $this->repo->findByNopol($data['nopol']);
        if ($existing !== null) {
            abort(409, 'Nomor polisi sudah terdaftar');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): ArmadaModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['nopol']) && $data['nopol'] !== $record->nopol) {
            $existing = $this->repo->findByNopol($data['nopol']);
            if ($existing !== null) {
                abort(409, 'Nomor polisi sudah terdaftar');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
