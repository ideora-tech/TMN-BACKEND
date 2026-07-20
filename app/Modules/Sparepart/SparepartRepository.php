<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SparepartRepository implements SparepartRepositoryInterface
{
    private const DETAIL_SELECT = [
        'sparepart.id_sparepart', 'sparepart.id_perusahaan', 'sparepart.kode', 'sparepart.nama',
        'sparepart.id_kategori_sparepart', 'sparepart.satuan', 'sparepart.harga_standar', 'sparepart.stok',
        'sparepart.aktif', 'sparepart.dibuat_pada', 'sparepart.dibuat_oleh',
        'sparepart.diubah_pada', 'sparepart.diubah_oleh', 'sparepart.dihapus_pada', 'sparepart.dihapus_oleh',
        'kategori_sparepart.nama as nama_kategori_sparepart',
    ];

    private const MUTASI_COLUMNS = [
        'id_mutasi', 'id_sparepart', 'jenis', 'qty', 'harga', 'id_perawatan', 'keterangan', 'tanggal',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    private function detailQuery()
    {
        return DB::table('sparepart')
            ->leftJoin('kategori_sparepart', function ($join) {
                $join->on('kategori_sparepart.id_kategori_sparepart', '=', 'sparepart.id_kategori_sparepart')
                     ->whereNull('kategori_sparepart.dihapus_pada');
            })
            ->whereNull('sparepart.dihapus_pada')
            ->select(self::DETAIL_SELECT);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search, ?string $idKategoriSparepart = null): LengthAwarePaginator
    {
        return $this->detailQuery()
            ->where('sparepart.id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('sparepart.nama', 'like', "%{$search}%")
                   ->orWhere('sparepart.kode', 'like', "%{$search}%");
            }))
            ->when($idKategoriSparepart, fn ($q, $v) => $q->where('sparepart.id_kategori_sparepart', $v))
            ->orderBy('sparepart.nama')
            ->paginate($limit, self::DETAIL_SELECT, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return $this->detailQuery()->where('sparepart.id_sparepart', $id)->first();
    }

    public function findByIdForUpdate(string $id): ?object
    {
        return DB::table('sparepart')
            ->select(['id_sparepart', 'nama', 'stok'])
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $id)
            ->lockForUpdate()
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode', $kode)
            ->when($excludeId, fn ($q) => $q->where('id_sparepart', '!=', $excludeId))
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_sparepart');
        DB::table('sparepart')->insert($data);
        return $this->findById($data['id_sparepart']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('sparepart')
            ->where('id_sparepart', $record->id_sparepart)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_sparepart);
    }

    public function delete(object $record): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $record->id_sparepart)
            ->update(RecordHelper::stampDelete());
    }

    public function countActiveUsage(string $idSparepart): int
    {
        return DB::table('perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->count();
    }

    public function setStok(string $id, int $stokBaru): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $id)
            ->update(RecordHelper::stampUpdate(['stok' => $stokBaru]));
    }

    public function insertMutasi(array $data): void
    {
        DB::table('sparepart_mutasi')->insert(RecordHelper::stampCreate($data, 'id_mutasi'));
    }

    public function paginateMutasi(string $idSparepart, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('sparepart_mutasi')
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->orderByDesc('dibuat_pada')
            ->orderByDesc('id_mutasi')
            ->paginate($limit, self::MUTASI_COLUMNS, 'page', $page);
    }
}
