<?php

declare(strict_types=1);

namespace App\Modules\Approval\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EventTypeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_event_type' => $this->id_event_type,
            'kode'          => $this->kode,
            'nama'          => $this->nama,
            'mode_resolusi' => $this->mode_resolusi,
            'aktif'         => (bool) $this->aktif,
            'dibuat_pada'   => $this->dibuat_pada,
        ];
    }
}
