<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RekonsiliasiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_rekonsiliasi'  => $this->id_rekonsiliasi,
            'id_faktur'        => $this->id_faktur,
            'catatan_klien'    => $this->catatan_klien,
            'catatan_keuangan' => $this->catatan_keuangan,
            'status'           => $this->status,
            'diselesaikan_pada' => $this->diselesaikan_pada?->toIso8601String(),
            'dibuat_pada'      => $this->dibuat_pada,
            'diubah_pada'      => $this->diubah_pada,
        ];
    }
}
