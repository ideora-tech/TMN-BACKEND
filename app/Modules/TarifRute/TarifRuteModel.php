<?php

declare(strict_types=1);

namespace App\Modules\TarifRute;

use App\Models\BaseModel;

class TarifRuteModel extends BaseModel
{
    protected $table = 'tarif_rute';
    protected $primaryKey = 'id_tarif_rute';

    protected $fillable = [
        'id_tarif_rute',
        'id_perusahaan',
        'id_rute',
        'id_jenis_kendaraan',
        'id_klien',
        'harga',
        'estimasi_tol',
        'estimasi_bbm',
        'estimasi_uang_jalan',
        'estimasi_biaya_lain',
        'tanggal_mulai',
        'tanggal_berakhir',
        'keterangan',
        'aktif',
    ];
}
