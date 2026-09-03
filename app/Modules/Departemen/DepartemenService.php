<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Modules\Departemen\Contracts\DepartemenRepositoryInterface;
use App\Support\KodeOtomatis;

class DepartemenService
{
    public function __construct(private readonly DepartemenRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?bool $aktif = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $aktif);

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

    public function tree(string $idPerusahaan): array
    {
        return $this->repo->tree($idPerusahaan);
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && (string) $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Departemen tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $idPerusahaan = (string) $data['id_perusahaan'];
        $data['kode_departemen'] = KodeOtomatis::berikutnya($idPerusahaan, 'departemen');
        if ($this->repo->findByKode($idPerusahaan, $data['kode_departemen'])) {
            abort(409, 'Kode departemen sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiRelasiAktif($id)) {
            abort(422, 'Departemen masih punya jabatan atau sub-departemen — pindahkan atau hapus dulu isinya');
        }

        $this->repo->delete($record);
    }
}
