<?php

declare(strict_types=1);

namespace App\Modules\Approval\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalPengajuanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_approval'     => $this->id_approval,
            'id_event_type'   => $this->id_event_type,
            'nama_event_type' => $this->nama_event_type ?? null,
            'id_referensi'    => $this->id_referensi,
            'nominal'         => $this->nominal !== null ? (float) $this->nominal : null,
            'status'          => $this->status,
            'alasan_ditolak'  => $this->alasan_ditolak,
            'nama_pengaju'    => $this->nama_pengaju ?? null,
            'kode_event_type'      => $this->kode_event_type ?? null,
            'nomor_referensi'      => $this->nomor_referensi ?? null,
            'keterangan_referensi' => $this->keterangan_referensi ?? null,
            'pihak_referensi'      => $this->pihak_referensi ?? null,
            'dibuat_pada'     => $this->dibuat_pada,
        ];
    }
}
