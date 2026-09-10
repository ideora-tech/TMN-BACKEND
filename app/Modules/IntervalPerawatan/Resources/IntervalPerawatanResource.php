<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan\Resources;

use App\Modules\IntervalPerawatan\IntervalLabelBuilder;
use Illuminate\Http\Resources\Json\JsonResource;

class IntervalPerawatanResource extends JsonResource
{
    public function toArray($request): array
    {
        $intervalKm = $this->interval_km !== null ? (int) $this->interval_km : null;
        $intervalBulan = $this->interval_bulan !== null ? (int) $this->interval_bulan : null;

        return [
            'id_interval_perawatan' => $this->id_interval_perawatan,
            'id_perusahaan'         => $this->id_perusahaan,
            'id_jenis_kendaraan'    => $this->id_jenis_kendaraan,
            'nama_jenis_kendaraan'  => $this->nama_jenis_kendaraan ?? null,
            'interval_km'           => $intervalKm,
            'interval_bulan'        => $intervalBulan,
            'label'                 => IntervalLabelBuilder::build($intervalKm, $intervalBulan),
            'sparepart'             => $this->sparepart ?? [],
            'aktif'                 => (bool) $this->aktif,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
        ];
    }
}
