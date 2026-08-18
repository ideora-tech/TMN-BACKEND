<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute;

use App\Models\BaseModel;

class ProyekRuteModel extends BaseModel
{
    protected $table = 'proyek_rute';
    protected $primaryKey = 'id_proyek_rute';

    protected $fillable = [
        'id_proyek_rute',
        'id_perusahaan',
        'id_proyek',
        'id_rute',
        'id_jenis_kendaraan',
        'harga_penawaran',
        'estimasi_ritase',
        'uang_jalan',
        'estimasi_tol',
        'estimasi_bbm',
        'estimasi_biaya_lain',
        'keterangan',
    ];

    protected $casts = [
        'estimasi_ritase' => 'integer',
    ];
}
