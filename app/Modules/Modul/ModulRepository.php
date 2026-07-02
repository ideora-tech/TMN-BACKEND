<?php

declare(strict_types=1);

namespace App\Modules\Modul;

use App\Modules\Modul\Contracts\ModulRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class ModulRepository implements ModulRepositoryInterface
{
    public function all(): array
    {
        return ModulModel::active()
            ->where('aktif', 1)
            ->orderBy('urutan')
            ->get()
            ->all();
    }

    public function paginate(int $page, int $limit): LengthAwarePaginator
    {
        return ModulModel::active()
            ->orderBy('urutan')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?ModulModel
    {
        return ModulModel::active()->find($id);
    }

    public function findByKode(string $kodeModul): ?ModulModel
    {
        return ModulModel::active()->where('kode_modul', $kodeModul)->first();
    }

    public function create(array $data): ModulModel
    {
        return ModulModel::create($data);
    }

    public function update(ModulModel $model, array $data): ModulModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ModulModel $model): void
    {
        $model->softDelete();
    }
}
