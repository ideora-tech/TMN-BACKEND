<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Models\BaseModel;

class FotoLaporanPerjalananModel extends BaseModel
{
    protected $table = 'foto_laporan_perjalanan';
    protected $primaryKey = 'id_foto';

    protected $fillable = [
        'id_foto',
        'id_laporan',
        'url_file',
        'keterangan',
    ];
}
