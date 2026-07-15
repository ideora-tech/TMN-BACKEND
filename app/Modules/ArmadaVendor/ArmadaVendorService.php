<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor;

use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;

class ArmadaVendorService
{
    public function __construct(private readonly ArmadaVendorRepositoryInterface $repo) {}

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

    public function findOrFail(string $id, string $idPerusahaan): ArmadaVendorModel
    {
        $record = $this->repo->findByIdMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Armada vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, string $idPerusahaan): ArmadaVendorModel
    {
        if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
            abort(404, 'Vendor tidak ditemukan');
        }
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): ArmadaVendorModel
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
