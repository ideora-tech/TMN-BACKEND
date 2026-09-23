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
        'jumlah_hari',
        'catatan',
        'alasan_ditolak_internal',
        'email_terkirim_ke',
        'email_terkirim_pada',
        'email_message_id',
        'email_gagal_pada',
        'email_gagal_alasan',
        'id_proyek',
        'id_penawaran_induk',
        'aktif',
    ];
}