<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan;

use App\Modules\Perusahaan\Contracts\PerusahaanRepositoryInterface;

class PerusahaanService
{
    public function __construct(private readonly PerusahaanRepositoryInterface $repo) {}

    public function list(int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginate($page, $limit);

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

    public function findOrFail(string $id): PerusahaanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Perusahaan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): PerusahaanModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): PerusahaanModel
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
