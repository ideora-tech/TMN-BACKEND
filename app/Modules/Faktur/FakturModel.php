<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Models\BaseModel;
use App\Modules\Klien\KlienModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'nomor_faktur',
        'total',
        'status',
        'tanggal_faktur',
        'jatuh_tempo',
    ];

    protected $casts = [
        'total'          => 'float',
        'tanggal_faktur' => 'date',
        'jatuh_tempo'    => 'date',
    ];

    public function klien(): BelongsTo
    {
        return $this->belongsTo(KlienModel::class, 'id_klien', 'id_klien');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FakturItemModel::class, 'id_faktur', 'id_faktur')
            ->whereNull('dihapus_pada');
    }
}
