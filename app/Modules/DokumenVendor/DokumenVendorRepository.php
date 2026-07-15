<?php

declare(strict_types=1);

namespace App\Modules\DokumenVendor;

use App\Modules\DokumenVendor\Contracts\DokumenVendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class DokumenVendorRepository implements DokumenVendorRepositoryInterface
{
    public function paginateByVendor(string $idVendor, int $page, int $limit): LengthAwarePaginator
    {
        return DokumenVendorModel::active()
            ->where('id_vendor', $idVendor)
            ->orderBy('berlaku_sampai')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?DokumenVendorModel
    {
        return DokumenVendorModel::active()->find($id);
    }

    public function findByIdUntukVendor(string $id, string $idVendor, string $idPerusahaan): ?DokumenVendorModel
    {
        return DokumenVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'dokumen_vendor.id_vendor')
            ->where('dokumen_vendor.id_dokumen_vendor', $id)
            ->where('dokumen_vendor.id_vendor', $idVendor)
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->select('dokumen_vendor.*')
            ->first();
    }

    public function findExpiring(string $idPerusahaan, int $days): array
    {
        return DokumenVendorModel::join('vendor', 'vendor.id_vendor', '=', 'dokumen_vendor.id_vendor')
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_vendor.dihapus_pada')
            ->whereNotNull('berlaku_sampai')
            ->where('berlaku_sampai', '<=', now()->addDays($days))
            ->select('dokumen_vendor.*')
            ->get()
            ->all();
    }

    public function create(array $data): DokumenVendorModel
    {
        return DokumenVendorModel::create($data);
    }

    public function update(DokumenVendorModel $model, array $data): DokumenVendorModel
    {
        $model->update($data);
        return $model->fresh() ?? $model;
    }

    public function delete(DokumenVendorModel $model): void
    {
        $model->softDelete();
    }
}
