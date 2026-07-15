<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;

class KontrakVendorService
{
    public function __construct(private readonly KontrakVendorRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idVendor = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idVendor);

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

    public function listByProyek(string $idPerusahaan, string $idProyek, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByProyek($idPerusahaan, $idProyek, $page, $limit);

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

    public function findOrFail(string $id): KontrakVendorModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Kontrak vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): KontrakVendorModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): KontrakVendorModel
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
