<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JadwalKeberangkatanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jadwal'          => $this->id_jadwal,
            'id_penugasan'       => $this->id_penugasan,
            'id_rute'            => $this->id_rute,
            'waktu_berangkat'    => $this->waktu_berangkat,
            'tgl_keberangkatan'  => $this->waktu_berangkat,
            'rute'               => $this->rute,
            'estimasi_tiba'      => $this->estimasi_tiba,
            'status'             => $this->resource->status ?? 'terjadwal',
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
