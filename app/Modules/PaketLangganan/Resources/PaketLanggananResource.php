<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaketLanggananResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_paket'      => $this->id_paket,
            'kode_paket'    => $this->kode_paket,
            'nama'          => $this->nama,
            'maks_karyawan' => $this->maks_karyawan,
            'harga'         => (float) $this->harga,
            'aktif'         => (bool) $this->aktif,
            'dibuat_pada'   => $this->dibuat_pada,
        ];
    }
}
