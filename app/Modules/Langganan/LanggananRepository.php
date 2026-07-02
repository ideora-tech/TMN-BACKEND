<?php

declare(strict_types=1);

namespace App\Modules\Langganan;

use App\Modules\Langganan\Contracts\LanggananRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class LanggananRepository implements LanggananRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return LanggananModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?LanggananModel
    {
        return LanggananModel::active()->find($id);
    }

    public function findActiveByPerusahaan(string $idPerusahaan): ?LanggananModel
    {
        return LanggananModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->first();
    }

    public function create(array $data): LanggananModel
    {
        return LanggananModel::create($data);
    }

    public function update(LanggananModel $model, array $data): LanggananModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(LanggananModel $model): void
    {
        $model->softDelete();
    }
}
