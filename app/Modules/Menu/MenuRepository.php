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

    public function allWithPerans(): array
    {
        return MenuModel::active()->with('perans')->orderBy('urutan')->get()->all();
    }

    public function semuaKodePeran(): array
    {
        return DB::table('peran')
            ->whereNull('dihapus_pada')
            ->pluck('kode_peran')
            ->map(fn ($k) => strtoupper($k))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Simpan hak akses menu untuk satu peran tanpa mengubah akses peran lain.
     * Menu tanpa baris menu_peran = terbuka untuk semua; bila peran ini
     * dicabut dari menu terbuka, akses peran lain dimaterialisasi dulu.
     * Baris terakhir sebuah menu tidak boleh hilang begitu saja (akan
     * membuat menu terbuka untuk semua) — fallback ke SUPERADMIN.
     */
    public function sinkronAksesPeran(string $kodePeran, array $idMenuTampil, array $semuaKodePeran): void
    {
        $kodePeran = strtoupper($kodePeran);

        DB::transaction(function () use ($kodePeran, $idMenuTampil, $semuaKodePeran) {
            foreach (MenuModel::active()->with('perans')->get() as $menu) {
                $bolehLihat = in_array($menu->id_menu, $idMenuTampil, true);
                $rows       = $menu->perans->pluck('kode_peran')->map(fn ($k) => strtoupper($k))->values()->all();
                $terbuka    = count($rows) === 0;

                if ($bolehLihat) {
                    if (!$terbuka && !in_array($kodePeran, $rows, true)) {
                        DB::table('menu_peran')->insertOrIgnore([
                            ['id_menu' => $menu->id_menu, 'kode_peran' => $kodePeran],
                        ]);
                    }
                    continue;
                }

                if ($terbuka) {
                    $lain = array_values(array_diff($semuaKodePeran, [$kodePeran]));
                    if (!empty($lain)) {
                        DB::table('menu_peran')->insertOrIgnore(array_map(
                            fn ($k) => ['id_menu' => $menu->id_menu, 'kode_peran' => $k],
                            $lain,
                        ));
                    }
                    continue;
                }

                if (in_array($kodePeran, $rows, true)) {
                    if (count($rows) === 1) {
                        if ($kodePeran === 'SUPERADMIN') {
                            continue;
                        }
                        DB::table('menu_peran')->insertOrIgnore([
                            ['id_menu' => $menu->id_menu, 'kode_peran' => 'SUPERADMIN'],
                        ]);
                    }
                    DB::table('menu_peran')
                        ->where('id_menu', $menu->id_menu)
                        ->whereRaw('UPPER(kode_peran) = ?', [$kodePeran])
                        ->delete();
                }
            }
        });
    }

    public function tree(?string $kodePeran = null): array
    {
        $query = MenuModel::active()->where('aktif', 1)->orderBy('urutan');

        if ($kodePeran !== null) {
            $role = strtolower($kodePeran);
            $query->where(function ($q) use ($role) {
                // Menu tanpa role di menu_peran = tampil untuk semua (misal Dashboard)
                $q->whereDoesntHave('perans')
                  // Menu yang punya role ini di menu_peran — LOWER() dua sisi agar
                  // kebal collation case-sensitive (kode_peran tersimpan huruf besar)
                  ->orWhereHas('perans', fn ($p) => $p->whereRaw('LOWER(kode_peran) = ?', [$role]));
            });
        }

        $all = $query->get();
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
