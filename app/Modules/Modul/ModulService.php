<?php

declare(strict_types=1);

namespace App\Modules\Modul;

use App\Modules\Modul\Contracts\ModulRepositoryInterface;

class ModulService
{
    public function __construct(private readonly ModulRepositoryInterface $repo) {}

    public function listAll(): array
    {
        return $this->repo->all();
    }

    public function list(int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginate($page, $limit);

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

    public function findOrFail(string $id): ModulModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Modul tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): ModulModel
    {
        $existing = $this->repo->findByKode($data['kode_modul']);
        if ($existing !== null) {
            abort(422, 'Kode modul sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data): ModulModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode_modul']) && $data['kode_modul'] !== $record->kode_modul) {
            $existing = $this->repo->findByKode($data['kode_modul']);
            if ($existing !== null) {
                abort(422, 'Kode modul sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
