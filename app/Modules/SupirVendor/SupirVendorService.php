<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor;

use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;

class SupirVendorService
{
    public function __construct(private readonly SupirVendorRepositoryInterface $repo) {}

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

    public function findOrFail(string $id, string $idPerusahaan): SupirVendorModel
    {
        $record = $this->repo->findByIdMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Supir vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, string $idPerusahaan): SupirVendorModel
    {
        if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
            abort(404, 'Vendor tidak ditemukan');
        }
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): SupirVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['id_vendor']) && $data['id_vendor'] !== $record->id_vendor) {
            if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
                abort(404, 'Vendor tidak ditemukan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }
}
