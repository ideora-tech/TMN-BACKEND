<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor;

use App\Models\BaseModel;

class PembayaranVendorModel extends BaseModel
{
    protected $table = 'pembayaran_vendor';
    protected $primaryKey = 'id_pembayaran_vendor';

    protected $fillable = [
        'id_pembayaran_vendor',
        'id_invoice_vendor',
        'tanggal_bayar',
        'nominal',
        'metode',
        'bank_pengirim',
        'no_referensi',
        'url_bukti',
        'catatan',
    ];

    protected $casts = [
        'tanggal_bayar' => 'date',
        'nominal'       => 'float',
    ];
}
