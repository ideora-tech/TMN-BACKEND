<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Models\BaseModel;

class PerawatanArmadaModel extends BaseModel
{
    protected $table = 'perawatan_armada';
    protected $primaryKey = 'id_perawatan';

    protected $fillable = [
        'id_perawatan',
        'id_armada',
        'tanggal',
        'jenis_perawatan',
        'biaya',
        'keterangan',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'biaya'   => 'decimal:2',
    ];
}
