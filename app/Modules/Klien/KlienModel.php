<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Models\BaseModel;

class KlienModel extends BaseModel
{
    protected $table = 'klien';
    protected $primaryKey = 'id_klien';

    protected $fillable = [
        'id_klien',
        'id_perusahaan',
        'kode_klien',
        'nama_klien',
        'email',
        'telepon',
        'alamat',
        'kontak_pic',
        'aktif',
    ];
}
