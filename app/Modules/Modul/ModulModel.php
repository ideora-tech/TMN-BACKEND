<?php

declare(strict_types=1);

namespace App\Modules\Modul;

use App\Models\BaseModel;

class ModulModel extends BaseModel
{
    protected $table = 'modul';
    protected $primaryKey = 'id_modul';

    protected $fillable = [
        'id_modul',
        'kode_modul',
        'nama_modul',
        'urutan',
        'aktif',
    ];
}
