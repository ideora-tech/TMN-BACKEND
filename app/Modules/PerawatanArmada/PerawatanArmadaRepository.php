<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PerawatanArmadaRepository implements PerawatanArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return PerawatanArmadaModel::active()
            ->where('id_armada', $idArmada)
            ->orderByDesc('tanggal')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?PerawatanArmadaModel
    {
        return PerawatanArmadaModel::active()->find($id);
    }

    public function create(array $data): PerawatanArmadaModel
    {
        return PerawatanArmadaModel::create($data);
    }

    public function update(PerawatanArmadaModel $model, array $data): PerawatanArmadaModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PerawatanArmadaModel $model): void
    {
        $model->softDelete();
    }
}
