<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;

class ProyekService
{
    private const ALLOWED_STATUSES = ['draft', 'aktif', 'selesai', 'batal'];

    public function __construct(private readonly ProyekRepositoryInterface $repo) {}

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

    public function listByKlien(string $idKlien, int $page = 1, int $limit = 20): array
    {
        $result = $this->repo->paginateByKlien($idKlien, $page, $limit);

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

    public function findOrFail(string $id): ProyekModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Proyek tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): ProyekModel
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByKode($idPerusahaan, $data['kode_proyek'])) {
            abort(409, 'Kode proyek sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): ProyekModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode_proyek']) && $data['kode_proyek'] !== $record->kode_proyek) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_proyek'])) {
                abort(409, 'Kode proyek sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function updateStatus(string $id, string $status): ProyekModel
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            abort(422, 'Status tidak valid');
        }

        $record = $this->findOrFail($id);

        return $this->repo->update($record, ['status' => $status]);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
