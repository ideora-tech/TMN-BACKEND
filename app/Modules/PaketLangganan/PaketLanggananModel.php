<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan;

use App\Models\BaseModel;

class PaketLanggananModel extends BaseModel
{
    protected $table = 'paket_langganan';
    protected $primaryKey = 'id_paket';

    protected $fillable = [
        'id_paket',
        'kode_paket',
        'nama',
        'maks_karyawan',
        'harga',
        'aktif',
    ];
}
