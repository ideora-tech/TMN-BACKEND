<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Models\BaseModel;

class EvaluasiTripModel extends BaseModel
{
    protected $table = 'evaluasi_trip';
    protected $primaryKey = 'id_evaluasi';

    protected $fillable = [
        'id_evaluasi',
        'id_penugasan',
        'nilai_armada',
        'nilai_supir',
        'nilai_ketepatan_waktu',
        'nilai_kualitas',
        'nilai_harga',
        'nilai_responsif',
        'catatan',
        'id_dievaluasi_oleh',
    ];

    protected $casts = [
        'nilai_armada'          => 'integer',
        'nilai_supir'           => 'integer',
        'nilai_ketepatan_waktu' => 'integer',
        'nilai_kualitas'        => 'integer',
        'nilai_harga'           => 'integer',
        'nilai_responsif'       => 'integer',
    ];
}
