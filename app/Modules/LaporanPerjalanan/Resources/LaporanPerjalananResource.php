<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LaporanPerjalananResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_laporan'      => $this->id_laporan,
            'id_perusahaan'   => $this->id_perusahaan,
            'id_trip'         => $this->id_trip,
            'biaya_bbm'       => $this->biaya_bbm,
            'jarak_tempuh_km' => $this->jarak_tempuh_km,
            'uang_jalan'      => $this->uang_jalan,
            'catatan_insiden' => $this->catatan_insiden,
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
            'biaya_lain'      => BiayaLainTripResource::collection($this->whenLoaded('biayaLain')),
            'foto'            => FotoLaporanResource::collection($this->whenLoaded('foto')),
        ];
    }
}
