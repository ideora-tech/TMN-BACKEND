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

    public function aksesPeran(): array
    {
        return array_map(fn ($m) => [
            'id_menu'       => $m->id_menu,
            'nama_menu'     => $m->nama_menu,
            'path'          => $m->path,
            'id_menu_induk' => $m->id_menu_induk,
            'urutan'        => (int) $m->urutan,
            'aktif'         => (bool) $m->aktif,
            'kode_peran'    => $m->perans->pluck('kode_peran')->map(fn ($k) => strtoupper($k))->values()->all(),
        ], $this->repo->allWithPerans());
    }

    public function simpanAksesPeran(string $kodePeran, array $idMenuTampil): void
    {
        $semuaKode = $this->repo->semuaKodePeran();
        if (!in_array(strtoupper($kodePeran), $semuaKode, true)) {
            abort(404, 'Peran tidak ditemukan');
        }

        $this->repo->sinkronAksesPeran($kodePeran, $idMenuTampil, $semuaKode);
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
