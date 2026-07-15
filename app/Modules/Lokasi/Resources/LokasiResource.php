<?php

declare(strict_types=1);

namespace App\Modules\Lokasi\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LokasiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_lokasi'      => $this->id_lokasi,
            'id_perusahaan'  => $this->id_perusahaan,
            'nama_lokasi'    => $this->nama_lokasi,
            'alamat'         => $this->alamat,
            'kota'           => $this->kota,
            'aktif'          => (bool) $this->aktif,
            'dibuat_pada'    => $this->dibuat_pada,
            'diubah_pada'    => $this->diubah_pada,
        ];
    }
}
