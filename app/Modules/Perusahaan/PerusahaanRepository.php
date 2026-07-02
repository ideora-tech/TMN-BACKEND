<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan;

use App\Modules\Perusahaan\Contracts\PerusahaanRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PerusahaanRepository implements PerusahaanRepositoryInterface
{
    public function paginate(int $page, int $limit): LengthAwarePaginator
    {
        return PerusahaanModel::active()->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?PerusahaanModel
    {
        return PerusahaanModel::active()->find($id);
    }

    public function create(array $data): PerusahaanModel
    {
        return PerusahaanModel::create($data);
    }

    public function update(PerusahaanModel $model, array $data): PerusahaanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PerusahaanModel $model): void
    {
        $model->softDelete();
    }
}
