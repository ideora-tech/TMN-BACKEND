<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Models\BaseModel;

class BiayaTagihanTripModel extends BaseModel
{
    protected $table = 'biaya_tagihan_trip';
    protected $primaryKey = 'id_biaya_tagihan';

    protected $fillable = [
        'id_biaya_tagihan',
        'id_laporan',
        'nama_biaya',
        'nominal',
    ];

    protected $casts = [
        'nominal' => 'float',
    ];
}
