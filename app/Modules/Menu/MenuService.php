<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Modules\Menu\Contracts\MenuRepositoryInterface;

class MenuService
{
    public function __construct(private readonly MenuRepositoryInterface $repo) {}

    public function listAktif(): array
    {
        return $this->repo->allAktif();
    }

    public function tree(?string $kodePeran = null): array
    {
        return $this->repo->tree($kodePeran);
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

    public function findOrFail(string $id): MenuModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Menu tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): MenuModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): MenuModel
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
