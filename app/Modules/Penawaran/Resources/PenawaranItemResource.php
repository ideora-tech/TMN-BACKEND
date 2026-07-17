<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PenawaranItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_penawaran_item'  => $this->id_penawaran_item,
            'id_penawaran'       => $this->id_penawaran,
            'id_rute'            => $this->id_rute,
            'id_jenis_kendaraan' => $this->id_jenis_kendaraan,
            'id_tarif_rute'      => $this->id_tarif_rute,
            'kode_rute'          => $this->kode_rute ?? null,
            'nama_rute'          => $this->nama_rute ?? null,
            'asal'               => $this->asal ?? null,
            'tujuan'             => $this->tujuan ?? null,
            'nama_jenis'         => $this->nama_jenis ?? null,
            'harga_satuan'       => (float) $this->harga_satuan,
            'estimasi_ritase'    => (int) $this->estimasi_ritase,
            'subtotal'           => (float) $this->subtotal,
            'keterangan'         => $this->keterangan,
        ];
    }
}
