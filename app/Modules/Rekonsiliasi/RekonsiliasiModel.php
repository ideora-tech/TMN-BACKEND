<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi;

use App\Models\BaseModel;
use App\Modules\Faktur\FakturModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RekonsiliasiModel extends BaseModel
{
    protected $table = 'rekonsiliasi';
    protected $primaryKey = 'id_rekonsiliasi';

    protected $fillable = [
        'id_rekonsiliasi',
        'id_faktur',
        'catatan_klien',
        'catatan_keuangan',
        'status',
        'diselesaikan_pada',
    ];

    protected $casts = [
        'diselesaikan_pada' => 'datetime',
    ];

    public function faktur(): BelongsTo
    {
        return $this->belongsTo(FakturModel::class, 'id_faktur', 'id_faktur');
    }
}
