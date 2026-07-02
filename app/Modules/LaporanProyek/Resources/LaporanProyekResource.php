<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LaporanProyekResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_laporan'         => $this->id_laporan,
            'id_proyek'          => $this->id_proyek,
            'ringkasan'          => $this->ringkasan,
            'total_trip'         => $this->total_trip,
            'id_diserahkan_oleh' => $this->id_diserahkan_oleh,
            'diserahkan_pada'    => $this->diserahkan_pada,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
