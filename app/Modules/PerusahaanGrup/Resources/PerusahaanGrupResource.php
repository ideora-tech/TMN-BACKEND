<?php

declare(strict_types=1);

namespace App\Modules\PerusahaanGrup\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PerusahaanGrupResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_perusahaan_grup' => $this->id_perusahaan_grup,
            'id_perusahaan'      => $this->id_perusahaan,
            'singkatan'          => $this->singkatan,
            'nama_lengkap'       => $this->nama_lengkap,
            'aktif'              => (bool) $this->aktif,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
