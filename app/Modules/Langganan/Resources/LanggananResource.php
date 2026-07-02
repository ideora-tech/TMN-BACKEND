<?php

declare(strict_types=1);

namespace App\Modules\Langganan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LanggananResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_langganan'     => $this->id_langganan,
            'id_perusahaan'    => $this->id_perusahaan,
            'kode_paket'       => $this->kode_paket,
            'maks_karyawan'    => $this->maks_karyawan,
            'mulai_pada'       => $this->mulai_pada,
            'kedaluwarsa_pada' => $this->kedaluwarsa_pada,
            'aktif'            => (bool) $this->aktif,
            'dibuat_pada'      => $this->dibuat_pada,
        ];
    }
}
