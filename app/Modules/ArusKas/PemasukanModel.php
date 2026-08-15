<?php

declare(strict_types=1);

namespace App\Modules\ArusKas;

use App\Models\BaseModel;

class PemasukanModel extends BaseModel
{
    protected $table = 'pemasukan';
    protected $primaryKey = 'id_pemasukan';

    protected $fillable = [
        'id_pemasukan',
        'id_perusahaan',
        'nomor_pemasukan',
        'kategori',
        'tanggal',
        'nominal',
        'sumber_dana',
        'keterangan',
        'url_bukti',
    ];
}
