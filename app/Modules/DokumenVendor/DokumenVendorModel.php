<?php

declare(strict_types=1);

namespace App\Modules\DokumenVendor;

use App\Models\BaseModel;

class DokumenVendorModel extends BaseModel
{
    protected $table = 'dokumen_vendor';
    protected $primaryKey = 'id_dokumen_vendor';

    protected $fillable = [
        'id_dokumen_vendor',
        'id_vendor',
        'jenis_dokumen',
        'nomor',
        'berlaku_sampai',
        'url_file',
    ];

    protected $casts = [
        'berlaku_sampai' => 'date',
    ];
}
