<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class KaryawanRepository implements KaryawanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return KaryawanModel::active()
            ->with(['jabatan', 'lokasi'])
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_karyawan')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?KaryawanModel
    {
        return KaryawanModel::active()->with(['jabatan', 'lokasi'])->find($id);
    }

    public function findByNik(string $nik): ?KaryawanModel
    {
        return KaryawanModel::active()->where('nik', $nik)->first();
    }

    public function create(array $data): KaryawanModel
    {
        return KaryawanModel::create($data);
    }

    public function update(KaryawanModel $model, array $data): KaryawanModel
    {
        $model->update($data);
        return $model->fresh(['jabatan', 'lokasi']);
    }

    public function delete(KaryawanModel $model): void
    {
        $model->softDelete();
    }

    public function exitHistory(string $idKaryawan): array
    {
        return \App\Modules\KaryawanExit\KaryawanExitModel::where('id_karyawan', $idKaryawan)
            ->orderBy('tanggal_efektif', 'desc')
            ->get()
            ->all();
    }
}
