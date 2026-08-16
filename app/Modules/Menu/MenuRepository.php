<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Modules\Menu\Contracts\MenuRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MenuRepository implements MenuRepositoryInterface
{
    public function allAktif(): array
    {
        return MenuModel::active()->where('aktif', 1)->orderBy('urutan')->get()->all();
    }

    public function tree(?string $kodePeran = null, ?string $idPerusahaan = null): array
    {
        $all = MenuModel::active()->where('aktif', 1)->orderBy('urutan')->get();

        if ($kodePeran !== null && strtoupper($kodePeran) !== 'SUPERADMIN') {
            $izinRows = DB::table('izin_peran')
                ->whereRaw('UPPER(kode_peran) = ?', [strtoupper($kodePeran)])
                ->where('aksi', 'lihat')
                ->whereNull('dihapus_pada')
                ->where(function ($q) use ($idPerusahaan) {
                    $q->where('id_perusahaan', $idPerusahaan)->orWhereNull('id_perusahaan');
                })
                ->get(['id_menu', 'diizinkan', 'id_perusahaan'])
                ->groupBy('id_menu');

            $bolehLihat = [];
            foreach ($izinRows as $idMenu => $rows) {
                $baris = $rows->first(fn ($r) => $r->id_perusahaan !== null)
                    ?? $rows->first(fn ($r) => $r->id_perusahaan === null);
                if ($baris !== null && (int) $baris->diizinkan === 1) {
                    $bolehLihat[$idMenu] = true;
                }
            }

            $byId   = $all->keyBy('id_menu');
            $tampil = [];
            foreach ($all as $m) {
                if ($m->path === null || !isset($bolehLihat[$m->id_menu])) {
                    continue;
                }
                $cur = $m;
                while ($cur !== null && !isset($tampil[$cur->id_menu])) {
                    $tampil[$cur->id_menu] = true;
                    $cur = $cur->id_menu_induk !== null ? ($byId[$cur->id_menu_induk] ?? null) : null;
                }
            }
            $all = $all->filter(fn ($m) => isset($tampil[$m->id_menu]))->values();
        }

        return $this->buildTree($all, null);
    }

    public function paginate(int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return MenuModel::active()
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nama_menu', 'like', "%{$search}%")
                   ->orWhere('path', 'like', "%{$search}%");
            }))
            ->orderBy('urutan')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?MenuModel
    {
        return MenuModel::active()->find($id);
    }

    public function create(array $data): MenuModel
    {
        return MenuModel::create($data);
    }

    public function update(MenuModel $model, array $data): MenuModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(MenuModel $model): void
    {
        $model->softDelete();
    }

    private function buildTree(Collection $items, ?string $parentId): array
    {
        return $items->where('id_menu_induk', $parentId)->values()->map(function ($item) use ($items) {
            $item->children = $this->buildTree($items, $item->id_menu);
            return $item;
        })->all();
    }
}
