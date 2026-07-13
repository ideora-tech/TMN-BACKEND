<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PenugasanRepository implements PenugasanRepositoryInterface
{
    public function paginateByProyek(string $idProyek, int $page, int $limit): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->where('id_proyek', $idProyek)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->where('id_armada', $idArmada)
            ->orderBy('tanggal_tugas', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateBySupir(string $idSupir, int $page, int $limit): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->where('id_supir', $idSupir)
            ->orderBy('tanggal_tugas', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function countSelesaiByProyek(string $idProyek): int
    {
        return PenugasanModel::active()
            ->where('id_proyek', $idProyek)
            ->where('status', 'selesai')
            ->count();
    }

    public function findById(string $id): ?PenugasanModel
    {
        return PenugasanModel::active()->find($id);
    }

    public function hasConflict(string $idKaryawan, string $tanggalTugas, ?string $excludeId = null): bool
    {
        $query = PenugasanModel::active()
            ->where('id_karyawan', $idKaryawan)
            ->where('tanggal_tugas', $tanggalTugas)
            ->whereIn('status', ['pending', 'aktif']);

        if ($excludeId !== null) {
            $query->where('id_penugasan', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function create(array $data): PenugasanModel
    {
        return PenugasanModel::create($data);
    }

    public function update(PenugasanModel $model, array $data): PenugasanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PenugasanModel $model): void
    {
        $model->softDelete();
    }
}
