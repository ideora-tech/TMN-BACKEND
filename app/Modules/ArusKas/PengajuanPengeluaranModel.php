<?php

declare(strict_types=1);

namespace App\Modules\ArusKas;

use App\Models\BaseModel;

class PengajuanPengeluaranModel extends BaseModel
{
    protected $table = 'pengajuan_pengeluaran';
    protected $primaryKey = 'id_pengajuan';

    protected $fillable = [
        'id_pengajuan',
        'id_perusahaan',
        'id_trip',
        'id_perawatan',
        'id_pembelian',
        'id_periode',
        'id_supir',
        'id_proyek',
        'periode_dari',
        'periode_sampai',
        'tarif_per_hari',
        'nomor_pengajuan',
        'kategori',
        'nominal',
        'tanggal_pengajuan',
        'penerima',
        'keterangan',
        'status',
        'alasan_ditolak',
        'dicek_oleh',
        'dicek_pada',
        'disetujui_oleh',
        'disetujui_pada',
        'ditransfer_oleh',
        'ditransfer_pada',
        'tanggal_transfer',
        'url_bukti',
    ];

    protected $attributes = [
        'status' => 'diajukan',
    ];
}
