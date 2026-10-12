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
        'id_kontrak_vendor',
        'id_supir_vendor_default',
        'nopol',
        'merk',
        'jenis',
        'id_jenis_kendaraan',
        'kapasitas',
        'tahun',
        'masa_berlaku_stnk',
        'masa_berlaku_kir',
        'harga_kontrak',
        'aktif',
    ];

    protected $casts = [
        'masa_berlaku_stnk' => 'date',
        'masa_berlaku_kir'  => 'date',
        'harga_kontrak'     => 'float',
    ];

    protected $attributes = [
        'aktif' => 1,
    ];
}
