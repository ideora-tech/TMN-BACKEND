<?php

declare(strict_types=1);

namespace App\Modules\Langganan;

use App\Modules\Langganan\Contracts\LanggananRepositoryInterface;

class LanggananService
{
    public function __construct(private readonly LanggananRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);

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

    public function findOrFail(string $id): LanggananModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Langganan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): LanggananModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): LanggananModel
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
