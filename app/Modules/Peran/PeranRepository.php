<?php

declare(strict_types=1);

namespace App\Modules\Peran;

use App\Modules\Peran\Contracts\PeranRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PeranRepository implements PeranRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $aktif = null): LengthAwarePaginator
    {
        $jumlahPengguna = DB::table('pengguna')
            ->whereColumn('pengguna.kode_peran', 'peran.kode_peran')
            ->where('pengguna.id_perusahaan', $idPerusahaan)
            ->whereNull('pengguna.dihapus_pada')
            ->selectRaw('COUNT(*)');

        return PeranModel::active()
            ->select('peran.*')
            ->selectSub($jumlahPengguna, 'jumlah_pengguna')
            ->where(function ($q) use ($idPerusahaan) {
                $q->where('id_perusahaan', $idPerusahaan)->orWhere('is_platform', 1);
            })
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nama_peran', 'like', "%{$search}%")
                   ->orWhere('kode_peran', 'like', "%{$search}%");
            }))
            ->when($aktif !== null && $aktif !== '', fn ($q) => $q->where('aktif', (int) $aktif))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?PeranModel
    {
        return PeranModel::active()->find($id);
    }

    public function findByKode(string $kodePeran): ?PeranModel
    {
        return PeranModel::active()->where('kode_peran', $kodePeran)->first();
    }

    public function create(array $data): PeranModel
    {
        return PeranModel::create($data);
    }

    public function update(PeranModel $model, array $data): PeranModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PeranModel $model): void
    {
        $model->softDelete();
    }
}
