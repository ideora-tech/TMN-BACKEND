<?php

declare(strict_types=1);

namespace App\Modules\Shift\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_shift'       => $this->id_shift,
            'id_perusahaan'  => $this->id_perusahaan,
            'nama'           => $this->nama,
            'jam_mulai'      => $this->jam_mulai,
            'jam_selesai'    => $this->jam_selesai,
            'aktif'          => (bool) $this->aktif,
            'dibuat_pada'    => $this->dibuat_pada,
            'diubah_pada'    => $this->diubah_pada,
        ];
    }
}
