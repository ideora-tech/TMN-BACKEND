<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProyekRepository implements ProyekRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        return ProyekModel::active()
            ->leftJoin('klien as k', 'k.id_klien', '=', 'proyek.id_klien')
            ->where('proyek.id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('proyek.nama_proyek', 'like', "%{$search}%")
                   ->orWhere('proyek.kode_proyek', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q, $v) => $q->where('proyek.status', $v))
            ->orderBy('proyek.dibuat_pada', 'desc')
            ->select('proyek.*', 'k.nama_klien')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByKlien(string $idKlien, string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        return ProyekModel::active()
            ->where('id_klien', $idKlien)
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nama_proyek', 'like', "%{$search}%")
                   ->orWhere('kode_proyek', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?ProyekModel
    {
        return ProyekModel::active()->find($id);
    }

    public function findByKode(string $idPerusahaan, string $kode): ?ProyekModel
    {
        return ProyekModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_proyek', $kode)
            ->first();
    }

    public function create(array $data): ProyekModel
    {
        return ProyekModel::create($data);
    }

    public function update(ProyekModel $model, array $data): ProyekModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ProyekModel $model): void
    {
        $model->softDelete();
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }
}
