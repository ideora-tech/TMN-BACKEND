<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan;

use App\Models\BaseModel;

class JadwalKeberangkatanModel extends BaseModel
{
    protected $table = 'jadwal_keberangkatan';
    protected $primaryKey = 'id_jadwal';

    protected $fillable = [
        'id_jadwal',
        'id_penugasan',
        'id_rute',
        'waktu_berangkat',
        'rute',
        'estimasi_tiba',
    ];
}
