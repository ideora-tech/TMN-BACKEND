<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_vendor'    => $this->id_vendor,
            'id_perusahaan' => $this->id_perusahaan,
            'kode_vendor'  => $this->kode_vendor,
            'nama_vendor'  => $this->nama_vendor,
            'email'        => $this->email,
            'telepon'      => $this->telepon,
            'alamat'       => $this->alamat,
            'aktif'        => (bool) $this->aktif,
            'dibuat_pada'  => $this->dibuat_pada,
            'diubah_pada'  => $this->diubah_pada,
        ];
    }
}
