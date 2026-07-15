<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class KontrakVendorRepository implements KontrakVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return KontrakVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByProyek(string $idPerusahaan, string $idProyek, int $page, int $limit): LengthAwarePaginator
    {
        return KontrakVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_proyek', $idProyek)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?KontrakVendorModel
    {
        return KontrakVendorModel::active()->find($id);
    }

    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?KontrakVendorModel
    {
        return KontrakVendorModel::active()
            ->where('id_kontrak_vendor', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function create(array $data): KontrakVendorModel
    {
        return KontrakVendorModel::create($data);
    }

    public function update(KontrakVendorModel $model, array $data): KontrakVendorModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(KontrakVendorModel $model): void
    {
        $model->softDelete();
    }
}
