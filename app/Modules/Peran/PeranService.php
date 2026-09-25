<?php

declare(strict_types=1);

namespace App\Modules\Peran;

use App\Modules\Peran\Contracts\PeranRepositoryInterface;

class PeranService
{
    public function __construct(private readonly PeranRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $aktif = null): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $search, $aktif);

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

    public function findOrFail(string $id): PeranModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Peran tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): PeranModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): PeranModel
    {
        $record = $this->findOrFail($id);
        if (array_key_exists('kode_peran', $data)
            && strtoupper(trim((string) $data['kode_peran'])) !== strtoupper((string) $record->kode_peran)) {
            abort(422, 'Kode peran tidak bisa diubah karena dipakai akun pengguna dan izin akses');
        }
        unset($data['kode_peran']);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
