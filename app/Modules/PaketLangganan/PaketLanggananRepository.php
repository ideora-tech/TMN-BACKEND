<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan;

use App\Modules\PaketLangganan\Contracts\PaketLanggananRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PaketLanggananRepository implements PaketLanggananRepositoryInterface
{
    public function paginate(int $page, int $limit): LengthAwarePaginator
    {
        return PaketLanggananModel::active()->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?PaketLanggananModel
    {
        return PaketLanggananModel::active()->find($id);
    }

    public function findByKode(string $kode): ?PaketLanggananModel
    {
        return PaketLanggananModel::active()->where('kode_paket', $kode)->first();
    }

    public function create(array $data): PaketLanggananModel
    {
        return PaketLanggananModel::create($data);
    }

    public function update(PaketLanggananModel $model, array $data): PaketLanggananModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PaketLanggananModel $model): void
    {
        $model->softDelete();
    }
}
