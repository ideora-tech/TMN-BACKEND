<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm;

use App\Models\BaseModel;

class HargaBbmModel extends BaseModel
{
    protected $table = 'harga_bbm';
    protected $primaryKey = 'id_harga_bbm';

    protected $fillable = [
        'id_harga_bbm',
        'id_jenis_bbm',
        'harga_per_liter',
        'berlaku_mulai',
    ];

    protected $casts = [
        'harga_per_liter' => 'float',
        'berlaku_mulai'   => 'date',
    ];
}
