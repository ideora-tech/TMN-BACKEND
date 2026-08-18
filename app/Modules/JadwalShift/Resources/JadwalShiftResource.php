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
            'nopol_alokasi'   => $this->nopol_alokasi ?? null,
            'sumber_alokasi'  => $this->sumber_alokasi ?? null,
            'status_trip'     => $this->status_trip ?? null,
            'id_trip'         => $this->id_trip ?? null,
            'trips'           => $this->trips ?? [],
            'id_supir_pengganti'   => $this->id_supir_pengganti ?? null,
            'nama_supir_pengganti' => $this->nama_supir_pengganti ?? null,
            'id_armada_override'   => $this->id_armada_override ?? null,
            'nopol_override'       => $this->nopol_override ?? null,
            'titik_drop_override'  => $this->titik_drop_override ?? [],
        ];
    }
}
