<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Models\BaseModel;

class PenawaranModel extends BaseModel
{
    protected $table = 'penawaran';
    protected $primaryKey = 'id_penawaran';

    protected $fillable = [
        'id_penawaran',
        'id_perusahaan',
        'id_klien',
        'nomor_penawaran',
        'judul',
        'nilai_penawaran',
        'status',
        'tipe_harga',
        'tanggal_penawaran',
        'tanggal_berlaku',
        'catatan',
        'alasan_ditolak_internal',
        'id_proyek',
        'id_penawaran_induk',
        'aktif',
    ];
}