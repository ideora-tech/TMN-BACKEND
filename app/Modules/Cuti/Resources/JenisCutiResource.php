<?php

declare(strict_types=1);

namespace App\Modules\Cuti\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JenisCutiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jenis_cuti'    => $this->id_jenis_cuti,
            'nama_jenis'       => $this->nama_jenis,
            'mengurangi_saldo' => (bool) $this->mengurangi_saldo,
            'aktif'            => (bool) $this->aktif,
            'keterangan'       => $this->keterangan,
        ];
    }
}
