<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm;

use App\Modules\JenisBbm\Contracts\JenisBbmRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class JenisBbmRepository implements JenisBbmRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return JenisBbmModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where('nama_bbm', 'like', "%{$search}%"))
            ->orderBy('nama_bbm')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?JenisBbmModel
    {
        return JenisBbmModel::active()->find($id);
    }

    public function findByIdMilik(string $id, string $idPerusahaan): ?JenisBbmModel
    {
        return JenisBbmModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->find($id);
    }

    public function create(array $data): JenisBbmModel
    {
        return JenisBbmModel::create($data);
    }

    public function update(JenisBbmModel $model, array $data): JenisBbmModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(JenisBbmModel $model): void
    {
        $model->softDelete();
    }

    public function hargaEfektif(string $idJenisBbm): ?float
    {
        $row = HargaBbmModel::active()
            ->where('id_jenis_bbm', $idJenisBbm)
            ->whereDate('berlaku_mulai', '<=', now()->toDateString())
            ->orderByDesc('berlaku_mulai')
            ->orderByDesc('dibuat_pada')
            ->first();

        return $row !== null ? (float) $row->harga_per_liter : null;
    }

    public function riwayatHarga(string $idJenisBbm): array
    {
        return HargaBbmModel::active()
            ->where('id_jenis_bbm', $idJenisBbm)
            ->orderByDesc('berlaku_mulai')
            ->orderByDesc('dibuat_pada')
            ->get()
            ->all();
    }

    public function createHarga(string $idJenisBbm, array $data): HargaBbmModel
    {
        return HargaBbmModel::create(array_merge($data, ['id_jenis_bbm' => $idJenisBbm]));
    }
}
