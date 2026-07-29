<?php

declare(strict_types=1);

namespace App\Modules\RekeningVendor;

use App\Models\BaseModel;

class RekeningVendorModel extends BaseModel
{
    protected $table = 'rekening_vendor';
    protected $primaryKey = 'id_rekening_vendor';

    protected $fillable = [
        'id_rekening_vendor',
        'id_vendor',
        'nama_bank',
        'nomor_rekening',
        'atas_nama',
        'cabang',
        'mata_uang',
    ];

    protected $attributes = [
        'mata_uang' => 'IDR',
    ];
}
