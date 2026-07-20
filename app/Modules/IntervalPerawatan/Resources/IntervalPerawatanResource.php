<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class IntervalPerawatanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_interval_perawatan' => $this->id_interval_perawatan,
            'id_perusahaan'         => $this->id_perusahaan,
            'id_jenis_perawatan'    => $this->id_jenis_perawatan,
            'id_jenis_kendaraan'    => $this->id_jenis_kendaraan,
            'nama_jenis_perawatan'  => $this->nama_jenis_perawatan ?? null,
            'nama_jenis_kendaraan'  => $this->nama_jenis_kendaraan ?? null,
            'interval_hari'         => (int) $this->interval_hari,
            'aktif'                 => (bool) $this->aktif,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
        ];
    }
}
