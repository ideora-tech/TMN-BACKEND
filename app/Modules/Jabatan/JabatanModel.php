<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Models\BaseModel;

class JabatanModel extends BaseModel
{
    protected $table = 'jabatan';
    protected $primaryKey = 'id_jabatan';

    protected $fillable = [
        'id_jabatan',
        'id_perusahaan',
        'id_departemen',
        'id_peran',
        'kode_jabatan',
        'nama_jabatan',
        'level',
        'aktif',
    ];
}
