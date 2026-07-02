<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;

class KaryawanService
{
    public function __construct(private readonly KaryawanRepositoryInterface $repo) {}

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

    public function findOrFail(string $id): KaryawanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Karyawan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): KaryawanModel
    {
        $existing = $this->repo->findByNik($data['nik']);
        if ($existing !== null) {
            abort(422, 'NIK sudah terdaftar');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data): KaryawanModel
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    public function exitHistory(string $id): array
    {
        $this->findOrFail($id);
        return $this->repo->exitHistory($id);
    }
}
