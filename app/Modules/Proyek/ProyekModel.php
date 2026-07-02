<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Models\BaseModel;

class ProyekModel extends BaseModel
{
    protected $table = 'proyek';
    protected $primaryKey = 'id_proyek';

    protected $fillable = [
        'id_proyek',
        'id_perusahaan',
        'id_klien',
        'kode_proyek',
        'nama_proyek',
        'tanggal_mulai',
        'tanggal_selesai',
        'status',
        'keterangan',
    ];
}
