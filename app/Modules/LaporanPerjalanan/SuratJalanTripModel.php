<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Models\BaseModel;

class SuratJalanTripModel extends BaseModel
{
    protected $table = 'surat_jalan_trip';
    protected $primaryKey = 'id_surat_jalan';

    protected $fillable = [
        'id_surat_jalan',
        'id_laporan',
        'id_titik_drop',
        'urutan',
        'no_surat_jalan',
    ];

    protected $casts = [
        'urutan' => 'integer',
    ];
}
