<?php

declare(strict_types=1);

namespace App\Modules\SupirProyek;

use App\Models\BaseModel;

class SupirProyekModel extends BaseModel
{
    protected $table = 'supir_proyek';
    protected $primaryKey = 'id_supir_proyek';

    protected $fillable = [
        'id_supir_proyek',
        'id_perusahaan',
        'id_proyek',
        'id_supir',
    ];
}
