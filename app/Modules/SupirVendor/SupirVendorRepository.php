<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor;

use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupirVendorRepository implements SupirVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null): LengthAwarePaginator
    {
        return SupirVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'supir_vendor.id_vendor')
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->when($idVendor, fn ($q) => $q->where('supir_vendor.id_vendor', $idVendor))
            ->select('supir_vendor.*', 'vendor.nama_vendor')
            ->orderBy('supir_vendor.nama')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?SupirVendorModel
    {
        return SupirVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'supir_vendor.id_vendor')
            ->where('supir_vendor.id_supir_vendor', $id)
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->select('supir_vendor.*', 'vendor.nama_vendor')
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

    public function milikVendor(string $id, string $idVendor): bool
    {
        return SupirVendorModel::active()
            ->where('id_supir_vendor', $id)
            ->where('id_vendor', $idVendor)
            ->exists();
    }

    public function create(array $data): SupirVendorModel
    {
        return SupirVendorModel::create($data);
    }

    public function update(SupirVendorModel $model, array $data): SupirVendorModel
    {
        $model->update($data);
        return $model->fresh() ?? $model;
    }

    public function delete(SupirVendorModel $model): void
    {
        $model->softDelete();
    }
}
