<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Models\BaseModel;

class PenugasanModel extends BaseModel
{
    protected $table = 'penugasan';
    protected $primaryKey = 'id_penugasan';

    protected $fillable = [
        'id_penugasan',
        'id_proyek',
        'id_armada',
        'id_supir',
        'id_karyawan',
        'tanggal_tugas',
        'status',
        'estimasi_biaya',
        'sumber',
        'id_kontrak_vendor',
        'id_armada_vendor',
        'id_supir_vendor',
    ];

    protected $attributes = [
        'sumber' => 'internal',
    ];
}
