<?php

declare(strict_types=1);

namespace App\Modules\RekeningVendor;

use App\Modules\RekeningVendor\Contracts\RekeningVendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RekeningVendorRepository implements RekeningVendorRepositoryInterface
{
    public function paginateByVendor(string $idVendor, int $page, int $limit): LengthAwarePaginator
    {
        return RekeningVendorModel::active()
            ->where('id_vendor', $idVendor)
            ->orderBy('nama_bank')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findByIdUntukVendor(string $id, string $idVendor, string $idPerusahaan): ?RekeningVendorModel
    {
        return RekeningVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'rekening_vendor.id_vendor')
            ->where('rekening_vendor.id_rekening_vendor', $id)
            ->where('rekening_vendor.id_vendor', $idVendor)
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->select('rekening_vendor.*')
            ->first();
    }

    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool
    {
        return DB::table('vendor')
            ->where('id_vendor', $idVendor)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function create(array $data): RekeningVendorModel
    {
        return RekeningVendorModel::create($data);
    }

    public function update(RekeningVendorModel $model, array $data): RekeningVendorModel
    {
        $model->update($data);
        return $model->fresh() ?? $model;
    }

    public function delete(RekeningVendorModel $model): void
    {
        $model->softDelete();
    }
}
