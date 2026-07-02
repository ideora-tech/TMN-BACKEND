<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan;

use App\Models\BaseModel;

class PerusahaanModel extends BaseModel
{
    protected $table = 'perusahaan';
    protected $primaryKey = 'id_perusahaan';

    protected $fillable = [
        'id_perusahaan',
        'nama',
        'email',
        'telepon',
        'alamat',
        'id_zona',
        'id_mata_uang',
        'aktif',
    ];
}
