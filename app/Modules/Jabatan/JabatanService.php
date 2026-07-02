<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;

class JabatanService
{
    public function __construct(private readonly JabatanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idDepartemen = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idDepartemen);

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

    public function findOrFail(string $id): JabatanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Jabatan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): JabatanModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): JabatanModel
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
