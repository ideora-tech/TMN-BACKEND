<?php

declare(strict_types=1);

namespace App\Modules\Approval\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalPengajuanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_approval'   => $this->id_approval,
            'id_event_type' => $this->id_event_type,
            'id_referensi'  => $this->id_referensi,
            'nominal'       => $this->nominal !== null ? (float) $this->nominal : null,
            'status'        => $this->status,
            'alasan_ditolak' => $this->alasan_ditolak,
            'dibuat_pada'   => $this->dibuat_pada,
        ];
    }
}
