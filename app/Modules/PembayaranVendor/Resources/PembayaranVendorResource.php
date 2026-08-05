<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class PembayaranVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_pembayaran_vendor' => $this->id_pembayaran_vendor,
            'id_invoice_vendor'    => $this->id_invoice_vendor,
            'tanggal_bayar'        => $this->tanggal_bayar?->toDateString(),
            'nominal'              => (float) $this->nominal,
            'metode'               => $this->metode,
            'bank_pengirim'        => $this->bank_pengirim,
            'no_referensi'         => $this->no_referensi,
            'url_bukti'            => PenyimpananBerkas::url($this->url_bukti),
            'catatan'              => $this->catatan,
            'dibuat_pada'          => $this->dibuat_pada,
            'diubah_pada'          => $this->diubah_pada,
        ];
    }
}
