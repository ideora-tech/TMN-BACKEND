<?php

declare(strict_types=1);

namespace App\Modules\AbsensiSupir;

use App\Modules\AbsensiSupir\Contracts\AbsensiSupirRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class AbsensiSupirRepository implements AbsensiSupirRepositoryInterface
{
    public function findBySupirTanggal(string $idSupir, string $tanggal): ?object
    {
        return DB::table('absensi_supir')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('tanggal', $tanggal)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_absensi');
        DB::table('absensi_supir')->insert($data);
        return DB::table('absensi_supir')->where('id_absensi', $data['id_absensi'])->first();
    }

    public function update(string $idAbsensi, array $data): void
    {
        DB::table('absensi_supir')
            ->where('id_absensi', $idAbsensi)
            ->update(RecordHelper::stampUpdate($data));
    }
}
