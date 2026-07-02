<?php

declare(strict_types=1);

namespace App\Modules\Vendor;

use App\Models\BaseModel;

class VendorModel extends BaseModel
{
    protected $table = 'vendor';
    protected $primaryKey = 'id_vendor';

    protected $fillable = [
        'id_vendor',
        'id_perusahaan',
        'kode_vendor',
        'nama_vendor',
        'email',
        'telepon',
        'alamat',
        'aktif',
    ];
}
