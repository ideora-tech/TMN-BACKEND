<?php

declare(strict_types=1);

namespace App\Modules\Langganan;

use App\Models\BaseModel;

class LanggananModel extends BaseModel
{
    protected $table = 'langganan';
    protected $primaryKey = 'id_langganan';

    protected $fillable = [
        'id_langganan',
        'id_perusahaan',
        'kode_paket',
        'maks_karyawan',
        'mulai_pada',
        'kedaluwarsa_pada',
        'aktif',
    ];
}
