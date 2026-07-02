<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JenisKendaraanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jenis_kendaraan' => $this->id_jenis_kendaraan,
            'id_perusahaan'      => $this->id_perusahaan,
            'kode_jenis'         => $this->kode_jenis,
            'nama_jenis'         => $this->nama_jenis,
            'kapasitas_muatan'   => $this->kapasitas_muatan !== null ? (float) $this->kapasitas_muatan : null,
            'aktif'              => (bool) $this->aktif,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
