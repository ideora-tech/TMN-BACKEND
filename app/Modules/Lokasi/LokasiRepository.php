<?php

declare(strict_types=1);

namespace App\Modules\Lokasi;

use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LokasiRepository implements LokasiRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return LokasiModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where('nama_lokasi', 'like', "%{$search}%"))
            ->orderBy('nama_lokasi')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?LokasiModel
    {
        return LokasiModel::active()->find($id);
    }

    public function create(array $data): LokasiModel
    {
        return LokasiModel::create($data);
    }

    public function update(LokasiModel $model, array $data): LokasiModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(LokasiModel $model): void
    {
        $model->softDelete();
    }

    public function dipakaiRute(string $idLokasi): bool
    {
        return DB::table('rute')
            ->whereNull('dihapus_pada')
            ->where(function ($q) use ($idLokasi) {
                $q->where('id_lokasi_asal', $idLokasi)
                  ->orWhere('id_lokasi_tujuan', $idLokasi);
            })
            ->exists();
    }
}
