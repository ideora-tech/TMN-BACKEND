<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FotoLaporanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_foto'    => $this->id_foto,
            'id_laporan' => $this->id_laporan,
            'url_file'   => $this->url_file,
            'keterangan' => $this->keterangan,
        ];
    }
}
