<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Models\BaseModel;

class KontrakVendorModel extends BaseModel
{
    protected $table = 'kontrak_vendor';
    protected $primaryKey = 'id_kontrak_vendor';

    protected $fillable = [
        'id_kontrak_vendor',
        'id_perusahaan',
        'id_vendor',
        'id_proyek',
        'mekanisme',
        'nilai_kontrak',
        'tanggal_mulai',
        'tanggal_selesai',
        'status',
    ];

    protected $casts = [
        'nilai_kontrak' => 'float',
    ];
}
