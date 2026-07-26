<?php

declare(strict_types=1);

namespace App\Modules\KontrakKaryawan;

use App\Modules\KontrakKaryawan\Contracts\KontrakKaryawanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class KontrakKaryawanRepository implements KontrakKaryawanRepositoryInterface
{
    private const COLUMNS = [
        'id_kontrak', 'id_perusahaan', 'id_karyawan', 'jenis_kontrak', 'nomor_kontrak',
        'tanggal_mulai', 'tanggal_selesai', 'keterangan',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function findAllByKaryawan(string $idKaryawan): array
    {
        return DB::table('kontrak_karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('dibuat_pada')
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        return DB::table('kontrak_karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_kontrak', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_kontrak');
        DB::table('kontrak_karyawan')->insert($data);
        return $this->findById($data['id_kontrak']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('kontrak_karyawan')
            ->where('id_kontrak', $record->id_kontrak)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_kontrak);
    }

    public function delete(object $record): void
    {
        DB::table('kontrak_karyawan')
            ->where('id_kontrak', $record->id_kontrak)
            ->update(RecordHelper::stampDelete());
    }
}
