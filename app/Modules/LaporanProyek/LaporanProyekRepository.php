<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek;

use App\Modules\LaporanProyek\Contracts\LaporanProyekRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class LaporanProyekRepository implements LaporanProyekRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return LaporanProyekModel::active()
            ->join('proyek as pr', 'laporan_proyek.id_proyek', '=', 'pr.id_proyek')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->select('laporan_proyek.*')
            ->orderBy('laporan_proyek.dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?LaporanProyekModel
    {
        return LaporanProyekModel::active()->find($id);
    }

    public function findByProyek(string $idProyek): ?LaporanProyekModel
    {
        return LaporanProyekModel::active()->where('id_proyek', $idProyek)->first();
    }

    public function existsByProyek(string $idProyek): bool
    {
        return LaporanProyekModel::active()->where('id_proyek', $idProyek)->exists();
    }

    public function create(array $data): LaporanProyekModel
    {
        return LaporanProyekModel::create($data);
    }

    public function update(LaporanProyekModel $model, array $data): LaporanProyekModel
    {
        $model->update($data);
        return $model->fresh();
    }
}
