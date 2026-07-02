<?php

declare(strict_types=1);

namespace App\Modules\Peran;

use App\Modules\Peran\Contracts\PeranRepositoryInterface;

class PeranService
{
    public function __construct(private readonly PeranRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit);

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

    public function findOrFail(string $id): PeranModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Peran tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): PeranModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): PeranModel
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
