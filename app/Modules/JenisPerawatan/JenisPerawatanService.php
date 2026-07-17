<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Modules\JenisPerawatan\Contracts\JenisPerawatanRepositoryInterface;

class JenisPerawatanService
{
    public function __construct(private readonly JenisPerawatanRepositoryInterface $repo) {}

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

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Jenis perawatan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): object
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);

        $dipakai = $this->repo->countActiveUsage($id);
        if ($dipakai > 0) {
            abort(422, "Jenis perawatan masih dipakai di {$dipakai} catatan perawatan aktif, tidak bisa dihapus");
        }

        $this->repo->delete($record);
    }
}
