<?php

declare(strict_types=1);

namespace App\Modules\BriefingSupir\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BriefingSupirResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_briefing'        => $this->id_briefing,
            'id_penugasan'       => $this->id_penugasan,
            'catatan_rute'       => $this->catatan_rute,
            'catatan_keselamatan'=> $this->catatan_keselamatan,
            'id_dibriefing_oleh' => $this->id_dibriefing_oleh,
            'waktu_briefing'     => $this->waktu_briefing,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
