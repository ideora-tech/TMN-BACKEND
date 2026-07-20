<?php
// app/Modules/KategoriSparepart/KategoriSparepartRepository.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart;

use App\Modules\KategoriSparepart\Contracts\KategoriSparepartRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KategoriSparepartRepository implements KategoriSparepartRepositoryInterface
{
    private const COLUMNS = [
        'id_kategori_sparepart', 'id_perusahaan', 'nama', 'keterangan', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('kategori_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('kategori_sparepart')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_kategori_sparepart', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_kategori_sparepart');
        DB::table('kategori_sparepart')->insert($data);
        return $this->findById($data['id_kategori_sparepart']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('kategori_sparepart')
            ->where('id_kategori_sparepart', $record->id_kategori_sparepart)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_kategori_sparepart);
    }

    public function delete(object $record): void
    {
        DB::table('kategori_sparepart')
            ->where('id_kategori_sparepart', $record->id_kategori_sparepart)
            ->update(RecordHelper::stampDelete());
    }

    public function countActiveUsage(string $idKategoriSparepart): int
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_kategori_sparepart', $idKategoriSparepart)
            ->count();
    }
}
