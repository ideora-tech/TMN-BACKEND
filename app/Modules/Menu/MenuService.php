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

    public function tree(?string $kodePeran = null, ?string $idPerusahaan = null): array
    {
        return $this->repo->tree($kodePeran, $idPerusahaan);
    }

    public function list(int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->paginate($page, $limit, $search);

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
        if (!empty($data['id_menu_induk'])) {
            $this->guardIndukGrup($data['id_menu_induk']);
        }
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): MenuModel
    {
        $record = $this->findOrFail($id);
        if (array_key_exists('id_menu_induk', $data) && $data['id_menu_induk'] !== null) {
            $this->guardIndukGrup($data['id_menu_induk']);
        }
        return $this->repo->update($record, $data);
    }

    private function guardIndukGrup(string $idInduk): void
    {
        $induk = $this->repo->findById($idInduk);
        if ($induk !== null && $induk->path !== null) {
            abort(422, 'Menu induk harus berupa grup (tanpa path)');
        }
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
