<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Models\BaseModel;

class PenawaranLampiranModel extends BaseModel
{
    protected $table = 'penawaran_lampiran';
    protected $primaryKey = 'id_lampiran';

    protected $fillable = [
        'id_lampiran',
        'id_penawaran',
        'url_file',
        'nama_asli',
        'urutan',
    ];

    protected $casts = [
        'urutan' => 'integer',
    ];
}
