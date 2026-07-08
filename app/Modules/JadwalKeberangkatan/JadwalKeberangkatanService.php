<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan;

use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;

class JadwalKeberangkatanService
{
    public function __construct(private readonly JadwalKeberangkatanRepositoryInterface $repo) {}

    public function list(string $idPenugasan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPenugasan($idPenugasan, $page, $limit);
        return $this->toPagedArray($result);
    }

    public function listByPerusahaan(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);
        return $this->toPagedArray($result);
    }

    private function toPagedArray($paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page'       => $paginator->currentPage(),
                'limit'      => $paginator->perPage(),
                'total'      => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): JadwalKeberangkatanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Jadwal keberangkatan tidak ditemukan');
        }
        return $record;
    }

    public function listBySupir(string $idSupir, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->findBySupir($idSupir, $page, $limit);

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

    public function create(array $data): JadwalKeberangkatanModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): JadwalKeberangkatanModel
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
