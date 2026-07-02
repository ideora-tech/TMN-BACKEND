<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi;

use App\Modules\Rekonsiliasi\Contracts\RekonsiliasiRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class RekonsiliasiRepository implements RekonsiliasiRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return RekonsiliasiModel::active()
            ->join('faktur', 'rekonsiliasi.id_faktur', '=', 'faktur.id_faktur')
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->whereNull('faktur.dihapus_pada')
            ->select('rekonsiliasi.*')
            ->orderBy('rekonsiliasi.dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByFaktur(string $idFaktur, int $page, int $limit): LengthAwarePaginator
    {
        return RekonsiliasiModel::active()
            ->where('id_faktur', $idFaktur)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?RekonsiliasiModel
    {
        return RekonsiliasiModel::active()->find($id);
    }

    public function create(array $data): RekonsiliasiModel
    {
        return RekonsiliasiModel::create($data);
    }

    public function update(RekonsiliasiModel $model, array $data): RekonsiliasiModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(RekonsiliasiModel $model): void
    {
        $model->softDelete();
    }
}
