<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupirVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_supir_vendor' => $this->id_supir_vendor,
            'id_vendor'       => $this->id_vendor,
            'nama'            => $this->nama,
            'telepon'         => $this->telepon,
            'no_sim'          => $this->no_sim,
            'aktif'           => (bool) $this->aktif,
            'nama_vendor'     => $this->whenNotNull($this->nama_vendor ?? null),
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
