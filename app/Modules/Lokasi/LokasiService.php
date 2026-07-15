<?php

declare(strict_types=1);

namespace App\Modules\Lokasi;

use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;

class LokasiService
{
    public function __construct(private readonly LokasiRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);

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

    public function findOrFail(string $id, string $idPerusahaan): LokasiModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Lokasi tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): LokasiModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): LokasiModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }
}
