<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProyekRuteResource extends JsonResource
{
    public function toArray($request): array
    {
        $rincian = $this->komponen_uang_jalan ?? [];

        return [
            'id_proyek_rute'      => $this->id_proyek_rute,
            'id_proyek'           => $this->id_proyek,
            'id_rute'             => $this->id_rute,
            'kode_rute'           => $this->kode_rute ?? null,
            'nama_rute'           => $this->nama_rute ?? null,
            'asal'                => $this->asal ?? null,
            'tujuan'              => $this->tujuan ?? null,
            'id_jenis_kendaraan'  => $this->id_jenis_kendaraan,
            'nama_jenis'          => $this->nama_jenis ?? null,
            'harga_penawaran'     => $this->harga_penawaran !== null ? (float) $this->harga_penawaran : null,
            'estimasi_ritase'     => (int) $this->estimasi_ritase,
            'subtotal'            => $this->harga_penawaran !== null
                ? (float) $this->harga_penawaran * (int) $this->estimasi_ritase
                : null,
            'uang_jalan'          => $this->uang_jalan !== null ? (float) $this->uang_jalan : null,
            'komponen_uang_jalan' => $rincian,
            'estimasi_biaya'      => $rincian === [] ? null : round(array_sum(array_column($rincian, 'nominal')), 2),
            'keterangan'          => $this->keterangan,
            'dibuat_pada'         => $this->dibuat_pada,
            'diubah_pada'         => $this->diubah_pada,
        ];
    }
}
