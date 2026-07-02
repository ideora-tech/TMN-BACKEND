<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class SupirRepository implements SupirRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return SupirModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama', 'asc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?SupirModel
    {
        return SupirModel::active()->find($id);
    }

    public function findByPengguna(string $idPengguna): ?SupirModel
    {
        return SupirModel::active()
            ->where('id_pengguna', $idPengguna)
            ->first();
    }

    public function create(array $data): SupirModel
    {
        return SupirModel::create($data);
    }

    public function update(SupirModel $model, array $data): SupirModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(SupirModel $model): void
    {
        $model->softDelete();
    }
}
