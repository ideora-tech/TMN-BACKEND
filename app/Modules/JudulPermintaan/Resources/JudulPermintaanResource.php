<?php

declare(strict_types=1);

namespace App\Modules\JudulPermintaan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JudulPermintaanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_judul_permintaan' => $this->id_judul_permintaan,
            'id_perusahaan'       => $this->id_perusahaan,
            'nama_judul'          => $this->nama_judul,
            'tipe'                => $this->tipe,
            'id_tipe_permintaan'  => $this->id_tipe_permintaan ?? null,
            'nama_tipe'           => $this->nama_tipe ?? null,
            'aktif'               => (bool) $this->aktif,
            'dibuat_pada'         => $this->dibuat_pada,
            'diubah_pada'         => $this->diubah_pada,
        ];
    }
}
