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
        'nomor_kontrak',
        'mekanisme',
        'jenis_layanan',
        'nilai_kontrak',
        'rate',
        'satuan',
        'pajak_persen',
        'termin_pembayaran_hari',
        'tanggal_mulai',
        'tanggal_selesai',
        'status',
    ];

    protected $casts = [
        'nilai_kontrak'          => 'float',
        'rate'                   => 'float',
        'pajak_persen'           => 'float',
        'termin_pembayaran_hari' => 'integer',
    ];
}
