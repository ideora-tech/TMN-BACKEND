<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SuratJalanTripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_surat_jalan' => $this->id_surat_jalan,
            'id_titik_drop'  => $this->id_titik_drop,
            'urutan_drop'    => $this->urutan_drop !== null ? (int) $this->urutan_drop : null,
            'lokasi_drop'    => $this->lokasi_drop,
            'urutan'         => (int) $this->urutan,
            'no_surat_jalan' => $this->no_surat_jalan,
        ];
    }
}
