<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi;

use App\Modules\Rekonsiliasi\Contracts\RekonsiliasiRepositoryInterface;

class RekonsiliasiService
{
    public function __construct(private readonly RekonsiliasiRepositoryInterface $repo) {}

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

    public function listByFaktur(string $idFaktur, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByFaktur($idFaktur, $page, $limit);

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

    public function findOrFail(string $id): RekonsiliasiModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Rekonsiliasi tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): RekonsiliasiModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): RekonsiliasiModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['status']) && $data['status'] === 'selesai' && $record->status !== 'selesai') {
            $data['diselesaikan_pada'] = now();
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
