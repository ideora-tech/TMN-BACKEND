<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Modules\Klien\Contracts\KlienRepositoryInterface;

class KlienService
{
    public function __construct(private readonly KlienRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $aktif = null): array
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

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Klien tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByKode($idPerusahaan, $data['kode_klien'])) {
            abort(409, 'Kode klien sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['kode_klien']) && $data['kode_klien'] !== $record->kode_klien) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_klien'])) {
                abort(409, 'Kode klien sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->punyaRelasiAktif($id)) {
            abort(422, 'Klien masih memiliki penawaran/proyek/invoice — nonaktifkan saja klien ini');
        }

        $this->repo->delete($record);
    }

    public function riwayatProyek(string $idKlien, string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $klien = $this->repo->findById($idKlien);
        if ($klien === null || $klien->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Klien tidak ditemukan');
        }

        $result = $this->repo->paginateProyek($idKlien, $page, $limit);

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
}
