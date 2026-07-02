<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class IzinPeranResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_izin'       => $this->id_izin,
            'id_perusahaan' => $this->id_perusahaan,
            'kode_peran'    => $this->kode_peran,
            'id_menu'       => $this->id_menu,
            'aksi'          => $this->aksi,
            'diizinkan'     => (bool) $this->diizinkan,
            'dibuat_pada'   => $this->dibuat_pada,
            'diubah_pada'   => $this->diubah_pada,
        ];
    }
}
