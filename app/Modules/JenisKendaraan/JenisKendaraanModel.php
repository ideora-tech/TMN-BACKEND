<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan;

use App\Models\BaseModel;

class JenisKendaraanModel extends BaseModel
{
    protected $table = 'jenis_kendaraan';
    protected $primaryKey = 'id_jenis_kendaraan';

    protected $fillable = [
        'id_jenis_kendaraan',
        'id_perusahaan',
        'kode_jenis',
        'nama_jenis',
        'kapasitas_muatan',
        'aktif',
    ];
}
