<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Models\BaseModel;

class BiayaLainTripModel extends BaseModel
{
    protected $table = 'biaya_lain_trip';
    protected $primaryKey = 'id_biaya_lain';

    protected $fillable = [
        'id_biaya_lain',
        'id_laporan',
        'nama_biaya',
        'nominal',
    ];

    protected $casts = [
        'nominal' => 'float',
    ];
}
