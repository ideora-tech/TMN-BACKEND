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
        'id_tarif_rute',
        'harga_penawaran',
        'keterangan',
    ];
}
