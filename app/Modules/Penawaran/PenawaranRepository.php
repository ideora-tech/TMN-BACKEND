<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PenawaranRepository implements PenawaranRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search,
        ?string $status
    ): LengthAwarePaginator {
        $query = PenawaranModel::active()
            ->where('id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('nomor_penawaran', 'like', "%{$search}%")
                  ->orWhere('judul', 'like', "%{$search}%");
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?PenawaranModel
    {
        return PenawaranModel::active()->find($id);
    }

    public function findByNomor(string $idPerusahaan, string $nomor, ?string $excludeId = null): ?PenawaranModel
    {
        $query = PenawaranModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_penawaran', $nomor);

        if ($excludeId !== null) {
            $query->where('id_penawaran', '!=', $excludeId);
        }

        return $query->first();
    }

    public function create(array $data): PenawaranModel
    {
        return PenawaranModel::create($data);
    }

    public function update(PenawaranModel $model, array $data): PenawaranModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PenawaranModel $model): void
    {
        $model->softDelete();
    }
}