<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Modules\Klien\Contracts\KlienRepositoryInterface;
use App\Modules\Proyek\ProyekModel;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KlienRepository implements KlienRepositoryInterface
{
    private const COLUMNS = [
        'id_klien', 'id_perusahaan', 'kode_klien', 'nama_klien', 'email', 'telepon',
        'alamat', 'kontak_pic', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('klien')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_klien')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('klien')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_klien', $id)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode): ?object
    {
        return DB::table('klien')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_klien', $kode)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_klien');
        DB::table('klien')->insert($data);
        return $this->findById($data['id_klien']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('klien')
            ->where('id_klien', $record->id_klien)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_klien);
    }

    public function delete(object $record): void
    {
        DB::table('klien')
            ->where('id_klien', $record->id_klien)
            ->update(RecordHelper::stampDelete());
    }

    public function paginateProyek(string $idKlien, int $page, int $limit): LengthAwarePaginator
    {
        return ProyekModel::active()
            ->where('id_klien', $idKlien)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }
}
