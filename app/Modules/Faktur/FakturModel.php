<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FakturModel extends BaseModel
{
    protected $table = 'faktur';
    protected $primaryKey = 'id_faktur';

    protected $fillable = [
        'id_faktur',
        'id_perusahaan',
        'id_proyek',
        'id_klien',
        'id_penawaran',
        'nomor_faktur',
        'total',
        'nama_pajak',
        'persen_pajak',
        'status',
        'alasan_ditolak_internal',
        'tanggal_faktur',
        'jatuh_tempo',
    ];

    protected $casts = [
        'total'        => 'float',
        'persen_pajak' => 'float',
        'tanggal_faktur' => 'date',
        'jatuh_tempo'    => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FakturItemModel::class, 'id_faktur', 'id_faktur')
            ->whereNull('dihapus_pada');
    }
}
