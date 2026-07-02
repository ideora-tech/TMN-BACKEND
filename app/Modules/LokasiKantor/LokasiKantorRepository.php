<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor;

use App\Modules\LokasiKantor\Contracts\LokasiKantorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class LokasiKantorRepository implements LokasiKantorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return LokasiKantorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_lokasi')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?LokasiKantorModel
    {
        return LokasiKantorModel::active()->find($id);
    }

    public function create(array $data): LokasiKantorModel
    {
        return LokasiKantorModel::create($data);
    }

    public function update(LokasiKantorModel $model, array $data): LokasiKantorModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(LokasiKantorModel $model): void
    {
        $model->softDelete();
    }
}
