<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Modules\Klien\Contracts\KlienRepositoryInterface;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Pagination\LengthAwarePaginator;

class KlienRepository implements KlienRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return KlienModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_klien')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?KlienModel
    {
        return KlienModel::active()->find($id);
    }

    public function findByKode(string $idPerusahaan, string $kode): ?KlienModel
    {
        return KlienModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_klien', $kode)
            ->first();
    }

    public function create(array $data): KlienModel
    {
        return KlienModel::create($data);
    }

    public function update(KlienModel $model, array $data): KlienModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(KlienModel $model): void
    {
        $model->softDelete();
    }

    public function paginateProyek(string $idKlien, int $page, int $limit): LengthAwarePaginator
    {
        return ProyekModel::active()
            ->where('id_klien', $idKlien)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }
}
