<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FakturItemModel extends BaseModel
{
    protected $table = 'faktur_item';
    protected $primaryKey = 'id_faktur_item';

    protected $fillable = [
        'id_faktur_item',
        'id_faktur',
        'deskripsi',
        'qty',
        'harga_satuan',
        'subtotal',
    ];

    protected $casts = [
        'qty'          => 'float',
        'harga_satuan' => 'float',
        'subtotal'     => 'float',
    ];

    public function faktur(): BelongsTo
    {
        return $this->belongsTo(FakturModel::class, 'id_faktur', 'id_faktur');
    }
}
