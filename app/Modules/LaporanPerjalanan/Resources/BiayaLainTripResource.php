<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BiayaLainTripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_biaya_lain' => $this->id_biaya_lain,
            'id_laporan'    => $this->id_laporan,
            'nama_biaya'    => $this->nama_biaya,
            'nominal'       => $this->nominal,
        ];
    }
}
