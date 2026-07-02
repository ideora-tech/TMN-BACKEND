<?php

declare(strict_types=1);

namespace App\Modules\Peran;

use App\Models\BaseModel;

class PeranModel extends BaseModel
{
    protected $table = 'peran';
    protected $primaryKey = 'id_peran';

    protected $fillable = [
        'id_peran',
        'id_perusahaan',
        'kode_peran',
        'nama_peran',
        'is_platform',
        'aktif',
    ];
}
