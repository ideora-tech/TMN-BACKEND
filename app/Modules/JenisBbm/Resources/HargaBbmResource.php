<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class HargaBbmResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_harga_bbm'    => $this->id_harga_bbm,
            'id_jenis_bbm'    => $this->id_jenis_bbm,
            'harga_per_liter' => (float) $this->harga_per_liter,
            'berlaku_mulai'   => $this->berlaku_mulai instanceof \Carbon\Carbon
                ? $this->berlaku_mulai->toDateString()
                : $this->berlaku_mulai,
            'dibuat_pada'     => $this->dibuat_pada,
        ];
    }
}
