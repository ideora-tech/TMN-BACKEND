<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PerawatanArmadaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_perawatan'    => $this->id_perawatan,
            'id_armada'       => $this->id_armada,
            'tanggal'         => $this->tanggal?->toDateString(),
            'jenis_perawatan' => $this->jenis_perawatan,
            'biaya'           => (float) $this->biaya,
            'keterangan'      => $this->keterangan,
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
