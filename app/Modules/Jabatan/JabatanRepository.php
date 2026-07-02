<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class JabatanRepository implements JabatanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idDepartemen = null): LengthAwarePaginator
    {
        $query = JabatanModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('level')
            ->orderBy('nama_jabatan');

        if ($idDepartemen !== null) {
            $query->where('id_departemen', $idDepartemen);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?JabatanModel
    {
        return JabatanModel::active()->find($id);
    }

    public function create(array $data): JabatanModel
    {
        return JabatanModel::create($data);
    }

    public function update(JabatanModel $model, array $data): JabatanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(JabatanModel $model): void
    {
        $model->softDelete();
    }
}
