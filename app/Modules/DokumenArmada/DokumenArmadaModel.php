<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Models\BaseModel;

class DokumenArmadaModel extends BaseModel
{
    protected $table = 'dokumen_armada';
    protected $primaryKey = 'id_dokumen_armada';

    protected $fillable = [
        'id_dokumen_armada',
        'id_armada',
        'jenis_dokumen',
        'nomor',
        'berlaku_sampai',
        'url_file',
    ];

    protected $casts = [
        'berlaku_sampai' => 'date',
    ];
}
