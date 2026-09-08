<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor;

use App\Models\BaseModel;

class PermintaanVendorModel extends BaseModel
{
    protected $table = 'permintaan_vendor';
    protected $primaryKey = 'id_permintaan';

    protected $fillable = [
        'id_permintaan',
        'id_perusahaan',
        'nomor_permintaan',
        'id_proyek',
        'id_jenis_kendaraan',
        'jumlah_unit',
        'mekanisme',
        'periode_dari',
        'periode_sampai',
        'catatan',
        'status',
        'alasan_ditolak',
        'id_kontrak_vendor',
    ];

    protected $casts = [
        'jumlah_unit' => 'integer',
    ];
}
