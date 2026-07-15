<?php

declare(strict_types=1);

namespace App\Modules\Lokasi;

use App\Models\BaseModel;

class LokasiModel extends BaseModel
{
    protected $table = 'lokasi';
    protected $primaryKey = 'id_lokasi';

    protected $fillable = [
        'id_lokasi',
        'id_perusahaan',
        'nama_lokasi',
        'alamat',
        'kota',
        'aktif',
    ];

    protected $attributes = [
        'aktif' => 1,
    ];
}
