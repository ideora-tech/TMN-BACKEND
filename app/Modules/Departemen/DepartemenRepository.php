<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Modules\Departemen\Contracts\DepartemenRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DepartemenRepository implements DepartemenRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DepartemenModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_departemen')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function tree(string $idPerusahaan): array
    {
        $all = DepartemenModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_departemen')
            ->get();

        return $this->buildTree($all, null);
    }

    public function findById(string $id): ?DepartemenModel
    {
        return DepartemenModel::active()->find($id);
    }

    public function create(array $data): DepartemenModel
    {
        return DepartemenModel::create($data);
    }

    public function update(DepartemenModel $model, array $data): DepartemenModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(DepartemenModel $model): void
    {
        $model->softDelete();
    }

    private function buildTree(Collection $items, ?string $parentId): array
    {
        return $items->where('id_departemen_induk', $parentId)->values()->map(function ($item) use ($items) {
            $item->children = $this->buildTree($items, $item->id_departemen);
            return $item;
        })->all();
    }
}
