<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JenisBbmResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jenis_bbm'    => $this->id_jenis_bbm,
            'id_perusahaan'   => $this->id_perusahaan,
            'nama_bbm'        => $this->nama_bbm,
            'aktif'           => (bool) $this->aktif,
            'harga_per_liter' => $this->harga_per_liter !== null ? (float) $this->harga_per_liter : null,
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
