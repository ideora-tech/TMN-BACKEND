<?php

declare(strict_types=1);

namespace App\Modules\Barang\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BarangMutasiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_mutasi'               => $this->id_mutasi,
            'jenis'                   => $this->jenis,
            'qty'                     => (int) $this->qty,
            'harga'                   => $this->harga !== null ? (float) $this->harga : null,
            'id_permintaan_pembelian' => $this->id_permintaan_pembelian,
            'nomor_permintaan'        => $this->nomor_permintaan ?? null,
            'pemakai'                 => $this->pemakai,
            'keterangan'              => $this->keterangan,
            'tanggal'                 => $this->tanggal,
            'dibuat_pada'             => $this->dibuat_pada,
        ];
    }
}
