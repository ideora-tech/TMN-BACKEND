<?php

declare(strict_types=1);

namespace App\Modules\Lokasi;

use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class LokasiRepository implements LokasiRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return LokasiModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where('nama_lokasi', 'like', "%{$search}%"))
            ->orderBy('nama_lokasi')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?LokasiModel
    {
        return LokasiModel::active()->find($id);
    }

    public function create(array $data): LokasiModel
    {
        return LokasiModel::create($data);
    }

    public function update(LokasiModel $model, array $data): LokasiModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(LokasiModel $model): void
    {
        $model->softDelete();
    }
}
