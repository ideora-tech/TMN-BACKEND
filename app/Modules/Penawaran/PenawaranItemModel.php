<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Models\BaseModel;

class PenawaranItemModel extends BaseModel
{
    protected $table = 'penawaran_item';
    protected $primaryKey = 'id_penawaran_item';

    protected $fillable = [
        'id_penawaran_item',
        'id_perusahaan',
        'id_penawaran',
        'id_rute',
        'id_jenis_kendaraan',
        'id_tarif_rute',
        'harga_satuan',
        'estimasi_ritase',
        'subtotal',
        'keterangan',
    ];

    protected $casts = [
        'harga_satuan'    => 'float',
        'estimasi_ritase' => 'integer',
        'subtotal'        => 'float',
    ];
}
