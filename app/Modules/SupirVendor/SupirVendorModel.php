<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor;

use App\Models\BaseModel;

class SupirVendorModel extends BaseModel
{
    protected $table = 'supir_vendor';
    protected $primaryKey = 'id_supir_vendor';

    protected $fillable = [
        'id_supir_vendor',
        'id_vendor',
        'nama',
        'telepon',
        'no_sim',
        'aktif',
    ];

    protected $attributes = [
        'aktif' => 1,
    ];
}
