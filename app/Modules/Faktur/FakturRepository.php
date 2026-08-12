<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FakturRepository implements FakturRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        $query = FakturModel::active()
            ->leftJoin('klien', function ($join) {
                $join->on('klien.id_klien', '=', 'faktur.id_klien')
                    ->whereNull('klien.dihapus_pada');
            })
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->select('faktur.*')
            ->orderBy('faktur.dibuat_pada', 'desc');

        if ($status !== null && $status !== '') {
            $query->where('faktur.status', $status);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('faktur.nomor_faktur', 'like', "%{$search}%")
                  ->orWhere('klien.nama_klien', 'like', "%{$search}%");
            });
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByKlien(string $idKlien, int $page, int $limit): LengthAwarePaginator
    {
        return FakturModel::active()
            ->where('id_klien', $idKlien)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?FakturModel
    {
        return FakturModel::active()->with('items')->find($id);
    }

    public function namaKlien(string $idKlien): ?string
    {
        return DB::table('klien')
            ->where('id_klien', $idKlien)
            ->whereNull('dihapus_pada')
            ->value('nama_klien');
    }

    public function namaProyek(string $idProyek): ?string
    {
        return DB::table('proyek')
            ->where('id_proyek', $idProyek)
            ->whereNull('dihapus_pada')
            ->value('nama_proyek');
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    public function findByNomor(string $nomor, string $idPerusahaan): ?FakturModel
    {
        return FakturModel::active()
            ->where('nomor_faktur', $nomor)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function nomorBerikutnya(string $idPerusahaan): string
    {
        $prefix = 'FK-' . now()->format('Ym') . '-';
        $terakhir = DB::table('faktur')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_faktur', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_faktur');
        $urut = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data): FakturModel
    {
        return FakturModel::create($data);
    }

    public function update(FakturModel $model, array $data): FakturModel
    {
        $model->update($data);
        return $model->fresh(['items']);
    }

    public function delete(FakturModel $model): void
    {
        $model->softDelete();
    }
}
