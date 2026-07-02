<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Models\BaseModel;

class KaryawanExitModel extends BaseModel
{
    protected $table = 'karyawan_exit';
    protected $primaryKey = 'id_exit';

    protected $fillable = [
        'id_exit',
        'id_perusahaan',
        'id_karyawan',
        'jenis_exit',
        'tanggal_efektif',
        'alasan',
        'dapat_direkrut_kembali',
    ];
}
