<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class ProyekRepository implements ProyekRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return ProyekModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?ProyekModel
    {
        return ProyekModel::active()->find($id);
    }

    public function findByKode(string $idPerusahaan, string $kode): ?ProyekModel
    {
        return ProyekModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_proyek', $kode)
            ->first();
    }

    public function create(array $data): ProyekModel
    {
        return ProyekModel::create($data);
    }

    public function update(ProyekModel $model, array $data): ProyekModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ProyekModel $model): void
    {
        $model->softDelete();
    }
}
