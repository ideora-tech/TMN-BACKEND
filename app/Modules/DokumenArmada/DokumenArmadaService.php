<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;

class DokumenArmadaService
{
    public function __construct(private readonly DokumenArmadaRepositoryInterface $repo) {}

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

    public function findOrFail(string $id): DokumenArmadaModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Dokumen armada tidak ditemukan');
        }
        return $record;
    }

    public function getExpiring(string $idPerusahaan, int $days): array
    {
        return $this->repo->findExpiring($idPerusahaan, $days);
    }

    public function create(string $idArmada, array $data): DokumenArmadaModel
    {
        return $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
