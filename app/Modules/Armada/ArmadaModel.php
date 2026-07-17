<?php

declare(strict_types=1);

namespace App\Modules\Armada;

use App\Models\BaseModel;

class ArmadaModel extends BaseModel
{
    protected $table = 'armada';
    protected $primaryKey = 'id_armada';

    protected $fillable = [
        'id_armada',
        'id_perusahaan',
        'id_jenis_kendaraan',
        'id_vendor',
        'nopol',
        'merk',
        'model',
        'tahun',
        'kepemilikan',
        'status',
        'aktif',
        'nomor_rangka',
        'nomor_mesin',
        'warna',
        'jenis_bahan_bakar',
        'kapasitas_muatan_kg',
        'tanggal_beli',
        'harga_beli',
        'kondisi_beli',
        'url_foto',
        'keterangan',
    ];
}
