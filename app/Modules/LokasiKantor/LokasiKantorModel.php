<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor;

use App\Models\BaseModel;

class LokasiKantorModel extends BaseModel
{
    protected $table = 'lokasi_kantor';
    protected $primaryKey = 'id_lokasi';

    protected $fillable = [
        'id_lokasi',
        'id_perusahaan',
        'kode_lokasi',
        'nama_lokasi',
        'alamat',
        'kota',
        'latitude',
        'longitude',
        'radius',
        'aktif',
    ];
}
