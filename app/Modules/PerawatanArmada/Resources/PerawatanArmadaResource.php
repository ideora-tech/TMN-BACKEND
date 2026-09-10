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
            'id_supplier'              => $this->id_supplier ?? null,
            'nama_supplier'            => $this->nama_supplier ?? null,
            'id_interval_perawatan'    => $this->id_interval_perawatan ?? null,
            'interval_label'           => $this->interval_label ?? null,
            'armada_nopol'             => $this->armada_nopol ?? null,
            'armada_merk'              => $this->armada_merk ?? null,
            'tanggal'                  => $this->tanggal,
            'biaya'                    => (float) $this->biaya,
            'km_odometer'              => $this->km_odometer !== null ? (int) $this->km_odometer : null,
            'status'                   => $this->status ?? 'selesai',
            'alasan_batal'             => $this->alasan_batal ?? null,
            'jadwal_servis_berikutnya' => $this->jadwal_servis_berikutnya,
            'keterangan'               => $this->keterangan,
            'sparepart'                => $this->sparepart ?? [],
            'bukti'                    => $this->bukti ?? [],
            'dibuat_pada'              => $this->dibuat_pada,
            'diubah_pada'              => $this->diubah_pada,
        ];
    }
}
