<?php

declare(strict_types=1);

namespace App\Modules\Peran;

use App\Modules\Peran\Contracts\PeranRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PeranRepository implements PeranRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return PeranModel::active()
            ->where(function ($q) use ($idPerusahaan) {
                $q->where('id_perusahaan', $idPerusahaan)->orWhere('is_platform', 1);
            })
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?PeranModel
    {
        return PeranModel::active()->find($id);
    }

    public function findByKode(string $kodePeran): ?PeranModel
    {
        return PeranModel::active()->where('kode_peran', $kodePeran)->first();
    }

    public function create(array $data): PeranModel
    {
        return PeranModel::create($data);
    }

    public function update(PeranModel $model, array $data): PeranModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PeranModel $model): void
    {
        $model->softDelete();
    }
}
