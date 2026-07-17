<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PerawatanArmadaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_perawatan'             => $this->id_perawatan,
            'id_armada'                => $this->id_armada,
            'id_jenis_perawatan'       => $this->id_jenis_perawatan ?? null,
            'armada_nopol'             => $this->armada_nopol ?? null,
            'armada_merk'              => $this->armada_merk ?? null,
            'tanggal'                  => $this->tanggal,
            'jenis_perawatan'          => $this->jenis_perawatan,
            'biaya'                    => (float) $this->biaya,
            'km_odometer'              => $this->km_odometer !== null ? (int) $this->km_odometer : null,
            'status'                   => $this->status ?? 'selesai',
            'jadwal_servis_berikutnya' => $this->jadwal_servis_berikutnya,
            'keterangan'               => $this->keterangan,
            'sparepart'                => $this->sparepart ?? [],
            'dibuat_pada'              => $this->dibuat_pada,
            'diubah_pada'              => $this->diubah_pada,
        ];
    }
}
