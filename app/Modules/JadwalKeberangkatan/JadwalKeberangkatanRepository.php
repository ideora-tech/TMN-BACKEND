<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan;

use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;
use App\Modules\Penugasan\PenugasanModel;
use Illuminate\Pagination\LengthAwarePaginator;

class JadwalKeberangkatanRepository implements JadwalKeberangkatanRepositoryInterface
{
    public function paginateByPenugasan(string $idPenugasan, int $page, int $limit): LengthAwarePaginator
    {
        return JadwalKeberangkatanModel::active()
            ->where('id_penugasan', $idPenugasan)
            ->orderBy('waktu_berangkat', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?JadwalKeberangkatanModel
    {
        return JadwalKeberangkatanModel::active()->find($id);
    }

    public function findBySupir(string $idSupir, int $page, int $limit): LengthAwarePaginator
    {
        $penugasanIds = PenugasanModel::active()
            ->where('id_karyawan', $idSupir)
            ->pluck('id_penugasan');

        return JadwalKeberangkatanModel::active()
            ->whereIn('id_penugasan', $penugasanIds)
            ->orderBy('waktu_berangkat', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function create(array $data): JadwalKeberangkatanModel
    {
        return JadwalKeberangkatanModel::create($data);
    }

    public function update(JadwalKeberangkatanModel $model, array $data): JadwalKeberangkatanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(JadwalKeberangkatanModel $model): void
    {
        $model->softDelete();
    }
}
