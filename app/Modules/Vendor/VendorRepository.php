<?php

declare(strict_types=1);

namespace App\Modules\Vendor;

use App\Modules\Vendor\Contracts\VendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class VendorRepository implements VendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return VendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_vendor')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?VendorModel
    {
        return VendorModel::active()->find($id);
    }

    public function findByKode(string $idPerusahaan, string $kode): ?VendorModel
    {
        return VendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_vendor', $kode)
            ->first();
    }

    public function create(array $data): VendorModel
    {
        return VendorModel::create($data);
    }

    public function update(VendorModel $model, array $data): VendorModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(VendorModel $model): void
    {
        $model->softDelete();
    }
}
