<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JenisPerawatanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jenis_perawatan' => $this->id_jenis_perawatan,
            'id_perusahaan'      => $this->id_perusahaan,
            'nama'               => $this->nama,
            'keterangan'         => $this->keterangan,
            'aktif'              => (bool) $this->aktif,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
