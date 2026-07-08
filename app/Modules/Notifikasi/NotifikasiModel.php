<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi;

use App\Models\BaseModel;

class NotifikasiModel extends BaseModel
{
    protected $table = 'notifikasi';
    protected $primaryKey = 'id_notifikasi';

    protected $fillable = [
        'id_notifikasi',
        'id_perusahaan',
        'id_pengguna',
        'judul',
        'isi',
        'tipe',
        'referensi_id',
        'referensi_tipe',
        'dibaca',
        'dibaca_pada',
        'aktif',
    ];
}