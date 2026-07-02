<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Modules\Menu\Contracts\MenuRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MenuRepository implements MenuRepositoryInterface
{
    public function allAktif(?string $kodeModul = null): array
    {
        $query = MenuModel::active()->where('aktif', 1)->orderBy('urutan');

        if ($kodeModul !== null) {
            $query->whereIn('id_menu', function ($sub) use ($kodeModul) {
                $sub->select('id_menu')
                    ->from('modul_menu')
                    ->where('kode_modul', $kodeModul);
            });
        }

        return $query->get()->all();
    }

    public function tree(): array
    {
        $all = MenuModel::active()->where('aktif', 1)->orderBy('urutan')->get();
        return $this->buildTree($all, null);
    }

    public function paginate(int $page, int $limit): LengthAwarePaginator
    {
        return MenuModel::active()
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
