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
        'km_odometer',
        'status',
        'jadwal_servis_berikutnya',
        'keterangan',
    ];

    protected $casts = [
        'tanggal'                  => 'date',
        'biaya'                    => 'decimal:2',
        'km_odometer'              => 'integer',
        'jadwal_servis_berikutnya' => 'date',
    ];
}
