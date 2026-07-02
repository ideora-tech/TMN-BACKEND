<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LokasiKantorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_lokasi'     => $this->id_lokasi,
            'id_perusahaan' => $this->id_perusahaan,
            'kode_lokasi'   => $this->kode_lokasi,
            'nama_lokasi'   => $this->nama_lokasi,
            'alamat'        => $this->alamat,
            'kota'          => $this->kota,
            'latitude'      => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude'     => $this->longitude !== null ? (float) $this->longitude : null,
            'radius'        => (int) $this->radius,
            'aktif'         => (bool) $this->aktif,
            'dibuat_pada'   => $this->dibuat_pada,
            'diubah_pada'   => $this->diubah_pada,
        ];
    }
}
