<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PerusahaanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_perusahaan' => $this->id_perusahaan,
            'nama'          => $this->nama,
            'email'         => $this->email,
            'telepon'       => $this->telepon,
            'alamat'        => $this->alamat,
            'id_zona'       => $this->id_zona,
            'id_mata_uang'  => $this->id_mata_uang,
            'aktif'         => (bool) $this->aktif,
            'dibuat_pada'   => $this->dibuat_pada,
        ];
    }
}
