<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Modules\Departemen\Contracts\DepartemenRepositoryInterface;

class DepartemenService
{
    public function __construct(private readonly DepartemenRepositoryInterface $repo) {}

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

    public function tree(string $idPerusahaan): array
    {
        return $this->repo->tree($idPerusahaan);
    }

    public function findOrFail(string $id): DepartemenModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Departemen tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): DepartemenModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): DepartemenModel
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
