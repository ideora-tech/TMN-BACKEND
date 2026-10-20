<?php

declare(strict_types=1);

namespace App\Modules\PerusahaanGrup;

use App\Modules\PerusahaanGrup\Contracts\PerusahaanGrupRepositoryInterface;

class PerusahaanGrupService
{
    public function __construct(private readonly PerusahaanGrupRepositoryInterface $repo) {}

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

    public function listAktif(string $idPerusahaan): array
    {
        return $this->repo->listAktifByPerusahaan($idPerusahaan);
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && (string) $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Perusahaan grup tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $data['singkatan'] = trim((string) $data['singkatan']);
        if ($this->repo->findBySingkatan((string) $data['id_perusahaan'], $data['singkatan']) !== null) {
            abort(409, "Singkatan {$data['singkatan']} sudah dipakai perusahaan grup lain");
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['singkatan'])) {
            $data['singkatan'] = trim((string) $data['singkatan']);
            if ($this->repo->findBySingkatan($idPerusahaan, $data['singkatan'], $record->id_perusahaan_grup) !== null) {
                abort(409, "Singkatan {$data['singkatan']} sudah dipakai perusahaan grup lain");
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiArmada($record->id_perusahaan_grup)) {
            abort(422, 'Perusahaan grup masih dipakai armada — nonaktifkan saja');
        }

        $this->repo->delete($record);
    }
}
