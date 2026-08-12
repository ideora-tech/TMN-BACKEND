<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor;

use App\Models\BaseModel;

class InvoiceVendorModel extends BaseModel
{
    protected $table = 'invoice_vendor';
    protected $primaryKey = 'id_invoice_vendor';

    protected $fillable = [
        'id_invoice_vendor',
        'id_perusahaan',
        'id_vendor',
        'id_kontrak_vendor',
        'nomor_invoice',
        'tanggal_invoice',
        'jatuh_tempo',
        'no_po',
        'no_do',
        'periode_dari',
        'periode_sampai',
        'dpp',
        'ppn',
        'pph',
        'total',
        'status',
        'catatan_verifikasi',
        'diverifikasi_oleh',
        'diverifikasi_pada',
        'status_pembayaran',
        'keterangan',
    ];

    protected $casts = [
        'tanggal_invoice'   => 'date',
        'jatuh_tempo'       => 'date',
        'dpp'               => 'float',
        'ppn'               => 'float',
        'pph'               => 'float',
        'total'             => 'float',
        'diverifikasi_pada' => 'datetime',
    ];
}
