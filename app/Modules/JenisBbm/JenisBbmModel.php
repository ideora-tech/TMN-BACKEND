<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm;

use App\Models\BaseModel;

class JenisBbmModel extends BaseModel
{
    protected $table = 'jenis_bbm';
    protected $primaryKey = 'id_jenis_bbm';

    protected $fillable = [
        'id_jenis_bbm',
        'id_perusahaan',
        'nama_bbm',
        'aktif',
    ];

    protected $attributes = [
        'aktif' => 1,
    ];
}
