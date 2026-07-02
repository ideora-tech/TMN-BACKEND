<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek;

use App\Models\BaseModel;

class LaporanProyekModel extends BaseModel
{
    protected $table = 'laporan_proyek';
    protected $primaryKey = 'id_laporan';

    protected $fillable = [
        'id_laporan',
        'id_proyek',
        'ringkasan',
        'total_trip',
        'id_diserahkan_oleh',
        'diserahkan_pada',
    ];

    protected $casts = [
        'total_trip'     => 'integer',
        'diserahkan_pada' => 'datetime',
    ];
}
