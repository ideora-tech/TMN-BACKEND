<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Modules\KaryawanExit\Contracts\KaryawanExitRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class KaryawanExitRepository implements KaryawanExitRepositoryInterface
{
    private const COLUMNS = [
        'id_exit', 'id_perusahaan', 'id_karyawan', 'jenis_exit', 'tanggal_efektif',
        'alasan', 'dapat_direkrut_kembali',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_exit');
        DB::table('karyawan_exit')->insert($data);
        return DB::table('karyawan_exit')->select(self::COLUMNS)->where('id_exit', $data['id_exit'])->first();
    }
}
