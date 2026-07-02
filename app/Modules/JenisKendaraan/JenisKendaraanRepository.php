<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan;

use App\Modules\JenisKendaraan\Contracts\JenisKendaraanRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class JenisKendaraanRepository implements JenisKendaraanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return JenisKendaraanModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_jenis')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?JenisKendaraanModel
    {
        return JenisKendaraanModel::active()->find($id);
    }

    public function findByKode(string $idPerusahaan, string $kode): ?JenisKendaraanModel
    {
        return JenisKendaraanModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_jenis', $kode)
            ->first();
    }

    public function create(array $data): JenisKendaraanModel
    {
        return JenisKendaraanModel::create($data);
    }

    public function update(JenisKendaraanModel $model, array $data): JenisKendaraanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(JenisKendaraanModel $model): void
    {
        $model->softDelete();
    }
}
