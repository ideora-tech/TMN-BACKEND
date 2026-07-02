<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;

class PerawatanArmadaService
{
    public function __construct(private readonly PerawatanArmadaRepositoryInterface $repo) {}

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByArmada($idArmada, $page, $limit);

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

    public function findOrFail(string $id): PerawatanArmadaModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Perawatan armada tidak ditemukan');
        }
        return $record;
    }

    public function create(string $idArmada, array $data): PerawatanArmadaModel
    {
        return $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
    }

    public function update(string $id, array $data): PerawatanArmadaModel
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
