<?php

declare(strict_types=1);

namespace App\Modules\RekeningVendor;

use App\Modules\RekeningVendor\Contracts\RekeningVendorRepositoryInterface;

class RekeningVendorService
{
    public function __construct(private readonly RekeningVendorRepositoryInterface $repo) {}

    public function listByVendor(string $idVendor, string $idPerusahaan, int $page = 1, int $limit = 100): array
    {
        $this->pastikanVendorMilikPerusahaan($idVendor, $idPerusahaan);

        $result = $this->repo->paginateByVendor($idVendor, $page, $limit);
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

    public function findOrFailUntukVendor(string $id, string $idVendor, string $idPerusahaan): RekeningVendorModel
    {
        $record = $this->repo->findByIdUntukVendor($id, $idVendor, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Rekening vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(string $idVendor, string $idPerusahaan, array $data): RekeningVendorModel
    {
        $this->pastikanVendorMilikPerusahaan($idVendor, $idPerusahaan);

        if (empty($data['mata_uang'])) {
            $data['mata_uang'] = 'IDR';
        }

        return $this->repo->create(array_merge($data, ['id_vendor' => $idVendor]));
    }

    public function update(string $id, string $idVendor, string $idPerusahaan, array $data): RekeningVendorModel
    {
        if (array_key_exists('mata_uang', $data) && empty($data['mata_uang'])) {
            $data['mata_uang'] = 'IDR';
        }

        $record = $this->findOrFailUntukVendor($id, $idVendor, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idVendor, string $idPerusahaan): void
    {
        $record = $this->findOrFailUntukVendor($id, $idVendor, $idPerusahaan);
        $this->repo->delete($record);
    }

    private function pastikanVendorMilikPerusahaan(string $idVendor, string $idPerusahaan): void
    {
        if (!$this->repo->vendorMilikPerusahaan($idVendor, $idPerusahaan)) {
            abort(404, 'Vendor tidak ditemukan');
        }
    }
}
