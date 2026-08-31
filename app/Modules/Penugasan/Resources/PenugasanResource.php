<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PenugasanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_penugasan'   => $this->id_penugasan,
            'id_proyek'      => $this->id_proyek,
            'id_armada'      => $this->id_armada,
            'id_supir'       => $this->id_supir,
            'id_karyawan'    => $this->id_karyawan,
            'id_rute'        => $this->id_rute,
            'id_pengajuan'   => $this->id_pengajuan,
            'tanggal_tugas'  => $this->tanggal_tugas,
            'status'         => $this->status,
            'estimasi_biaya' => $this->estimasi_biaya !== null ? (float) $this->estimasi_biaya : null,
            'sumber'            => $this->sumber,
            'id_kontrak_vendor' => $this->id_kontrak_vendor,
            'id_armada_vendor'  => $this->id_armada_vendor,
            'id_supir_vendor'   => $this->id_supir_vendor,
            'titik_drop'         => $this->titik_drop ?? [],
            'titik_drop_detail'  => $this->titik_drop_detail ?? [],
            'dibuat_pada'    => $this->dibuat_pada,
            'diubah_pada'    => $this->diubah_pada,
            'proyek' => $this->whenLoaded('proyek', fn () => $this->proyek !== null ? ['kode_proyek' => $this->proyek->kode_proyek, 'nama_proyek' => $this->proyek->nama_proyek] : null),
            'armada' => $this->whenLoaded('armada', fn () => $this->armada !== null ? ['nopol' => $this->armada->nopol] : null),
        ];
    }
}
