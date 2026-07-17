<?php

declare(strict_types=1);

namespace App\Modules\Trip\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_trip'         => $this->id_trip,
            'id_jadwal'       => $this->id_jadwal,
            'rute'            => $this->rute,
            'waktu_berangkat' => $this->waktu_berangkat,
            'supir_nama'      => $this->supir_nama,
            'armada_nopol'    => $this->armada_nopol,
            'waktu_checkin'   => $this->waktu_checkin,
            'waktu_checkout'  => $this->waktu_checkout,
            'status'          => $this->status,
            'catatan'         => $this->catatan,
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
