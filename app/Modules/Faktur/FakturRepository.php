<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class FakturRepository implements FakturRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return FakturModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByKlien(string $idKlien, int $page, int $limit): LengthAwarePaginator
    {
        return FakturModel::active()
            ->where('id_klien', $idKlien)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?FakturModel
    {
        return FakturModel::active()->with('items')->find($id);
    }

    public function findByNomor(string $nomor, string $idPerusahaan): ?FakturModel
    {
        return FakturModel::active()
            ->where('nomor_faktur', $nomor)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function create(array $data): FakturModel
    {
        return FakturModel::create($data);
    }

    public function update(FakturModel $model, array $data): FakturModel
    {
        $model->update($data);
        return $model->fresh(['items']);
    }

    public function delete(FakturModel $model): void
    {
        $model->softDelete();
    }
}
