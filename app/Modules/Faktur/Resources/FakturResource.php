<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FakturResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_faktur'      => $this->id_faktur,
            'id_perusahaan'  => $this->id_perusahaan,
            'id_proyek'      => $this->id_proyek,
            'id_klien'       => $this->id_klien,
            'nomor_faktur'   => $this->nomor_faktur,
            'total'          => $this->total,
            'status'         => $this->status,
            'tanggal_faktur' => $this->tanggal_faktur?->toDateString(),
            'jatuh_tempo'    => $this->jatuh_tempo?->toDateString(),
            'dibuat_pada'    => $this->dibuat_pada,
            'diubah_pada'    => $this->diubah_pada,
            'items'          => $this->whenLoaded('items', function () {
                return $this->items->map(fn($item) => [
                    'id_faktur_item' => $item->id_faktur_item,
                    'id_faktur'      => $item->id_faktur,
                    'deskripsi'      => $item->deskripsi,
                    'qty'            => $item->qty,
                    'harga_satuan'   => $item->harga_satuan,
                    'subtotal'       => $item->subtotal,
                ]);
            }),
        ];
    }
}
