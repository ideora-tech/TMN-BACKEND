<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JadwalShiftResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jadwal_shift' => $this->id_jadwal_shift,
            'id_proyek'       => $this->id_proyek,
            'id_shift'        => $this->id_shift,
            'id_supir'        => $this->id_supir,
            'tanggal'         => $this->tanggal,
            'shift_nama'      => $this->shift_nama,
            'jam_mulai'       => $this->jam_mulai,
            'jam_selesai'     => $this->jam_selesai,
        ];
    }
}
