<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor;

use App\Models\BaseModel;

class ArmadaVendorModel extends BaseModel
{
    protected $table = 'armada_vendor';
    protected $primaryKey = 'id_armada_vendor';

    protected $fillable = [
        'id_armada_vendor',
        'id_vendor',
        'nopol',
        'merk',
        'jenis',
        'id_jenis_kendaraan',
        'kapasitas',
        'tahun',
        'masa_berlaku_stnk',
        'masa_berlaku_kir',
        'aktif',
    ];

    protected $casts = [
        'masa_berlaku_stnk' => 'date',
        'masa_berlaku_kir'  => 'date',
    ];

    protected $attributes = [
        'aktif' => 1,
    ];
}
