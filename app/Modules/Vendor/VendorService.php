<?php

declare(strict_types=1);

namespace App\Modules\Vendor;

use App\Modules\Vendor\Contracts\VendorRepositoryInterface;

class VendorService
{
    public function __construct(private readonly VendorRepositoryInterface $repo) {}

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

    public function findOrFail(string $id): VendorModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): VendorModel
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByKode($idPerusahaan, $data['kode_vendor'])) {
            abort(409, 'Kode vendor sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): VendorModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode_vendor']) && $data['kode_vendor'] !== $record->kode_vendor) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_vendor'])) {
                abort(409, 'Kode vendor sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
