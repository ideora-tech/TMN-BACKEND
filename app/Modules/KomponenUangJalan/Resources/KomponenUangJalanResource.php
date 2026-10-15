<?php

declare(strict_types=1);

namespace App\Modules\KomponenUangJalan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KomponenUangJalanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_komponen_uang_jalan' => $this->id_komponen_uang_jalan,
            'nama'                   => $this->nama,
            'urutan'                 => (int) $this->urutan,
            'aktif'                  => (bool) $this->aktif,
            'dibuat_pada'            => $this->dibuat_pada,
            'diubah_pada'            => $this->diubah_pada,
        ];
    }
}
