<?php

declare(strict_types=1);

namespace App\Modules\Approval\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalRiwayatSayaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_approval'          => $this->id_approval,
            'id_referensi'         => $this->id_referensi,
            'kode_event_type'      => $this->kode_event_type,
            'nama_event_type'      => $this->nama_event_type,
            'nomor_referensi'      => $this->nomor_referensi,
            'keterangan_referensi' => $this->keterangan_referensi,
            'pihak_referensi'      => $this->pihak_referensi,
            'nama_pengaju'         => $this->nama_pengaju,
            'nominal'              => $this->nominal !== null ? (float) $this->nominal : null,
            'keputusan_saya'       => $this->keputusan_saya,
            'catatan_saya'         => $this->catatan_saya,
            'diputuskan_pada'      => $this->diputuskan_pada,
            'status_pengajuan'     => $this->status_pengajuan,
            'diajukan_pada'        => $this->diajukan_pada,
        ];
    }
}
