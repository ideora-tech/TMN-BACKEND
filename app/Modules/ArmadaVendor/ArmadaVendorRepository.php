<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor;

use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ArmadaVendorRepository implements ArmadaVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null): LengthAwarePaginator
    {
        return ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->when($idVendor, fn ($q) => $q->where('armada_vendor.id_vendor', $idVendor))
            ->select('armada_vendor.*', 'vendor.nama_vendor')
            ->orderBy('armada_vendor.nopol')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?ArmadaVendorModel
    {
        return ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->where('armada_vendor.id_armada_vendor', $id)
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->select('armada_vendor.*', 'vendor.nama_vendor')
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
        return ArmadaVendorModel::active()
            ->where('id_armada_vendor', $id)
            ->where('id_vendor', $idVendor)
            ->exists();
    }

    public function create(array $data): ArmadaVendorModel
    {
        return ArmadaVendorModel::create($data);
    }

    public function update(ArmadaVendorModel $model, array $data): ArmadaVendorModel
    {
        $model->update($data);
        return $model->fresh() ?? $model;
    }

    public function delete(ArmadaVendorModel $model): void
    {
        $model->softDelete();
    }
}
