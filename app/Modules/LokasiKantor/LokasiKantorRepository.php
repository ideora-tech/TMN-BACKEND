<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor;

use App\Modules\LokasiKantor\Contracts\LokasiKantorRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LokasiKantorRepository implements LokasiKantorRepositoryInterface
{
    private const COLUMNS = [
        'id_lokasi', 'id_perusahaan', 'kode_lokasi', 'nama_lokasi', 'alamat', 'kota',
        'latitude', 'longitude', 'radius', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('lokasi_kantor')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_lokasi')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('lokasi_kantor')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_lokasi', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_lokasi');
        DB::table('lokasi_kantor')->insert($data);
        return $this->findById($data['id_lokasi']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('lokasi_kantor')
            ->where('id_lokasi', $record->id_lokasi)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_lokasi);
    }

    public function delete(object $record): void
    {
        DB::table('lokasi_kantor')
            ->where('id_lokasi', $record->id_lokasi)
            ->update(RecordHelper::stampDelete());
    }
}
