<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PenugasanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_penugasan'  => $this->id_penugasan,
            'id_proyek'     => $this->id_proyek,
            'id_armada'     => $this->id_armada,
            'id_supir'      => $this->id_supir,
            'id_karyawan'   => $this->id_karyawan,
            'tanggal_tugas' => $this->tanggal_tugas,
            'status'        => $this->status,
            'dibuat_pada'   => $this->dibuat_pada,
            'diubah_pada'   => $this->diubah_pada,
        ];
    }
}
