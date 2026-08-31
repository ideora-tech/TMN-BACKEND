<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupirVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_supir_vendor'  => $this->id_supir_vendor,
            'id_pengguna'      => $this->id_pengguna ?? null,
            'id_vendor'        => $this->id_vendor,
            'id_kontrak_vendor' => $this->id_kontrak_vendor ?? null,
            'nama'             => $this->nama,
            'telepon'          => $this->telepon,
            'no_sim'           => $this->no_sim,
            'masa_berlaku_sim' => $this->masa_berlaku_sim?->toDateString(),
            'aktif'            => (bool) $this->aktif,
            'nama_vendor'     => $this->whenNotNull($this->nama_vendor ?? null),
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
