<?php

declare(strict_types=1);

namespace App\Modules\Peran\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PeranResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_peran'     => $this->id_peran,
            'id_perusahaan' => $this->id_perusahaan,
            'kode_peran'   => $this->kode_peran,
            'nama_peran'   => $this->nama_peran,
            'is_platform'  => (bool) $this->is_platform,
            'aktif'        => (bool) $this->aktif,
            'dibuat_pada'  => $this->dibuat_pada,
            'diubah_pada'  => $this->diubah_pada,
        ];
    }
}
