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
        'tahun',
        'aktif',
    ];

    protected $attributes = [
        'aktif' => 1,
    ];
}
