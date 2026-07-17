<?php

declare(strict_types=1);

namespace App\Modules\ParameterBok;

use App\Models\BaseModel;

class ParameterBokModel extends BaseModel
{
    protected $table = 'parameter_bok';
    protected $primaryKey = 'id_parameter_bok';

    protected $fillable = [
        'id_parameter_bok',
        'id_perusahaan',
        'id_jenis_kendaraan',
        'id_jenis_bbm',
        'konsumsi_km_per_liter',
        'biaya_ban_per_km',
        'biaya_servis_per_km',
        'biaya_tetap_bulanan',
        'utilisasi_km_per_bulan',
        'margin_persen',
        'keterangan',
        'aktif',
    ];
}
