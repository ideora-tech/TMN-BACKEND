<?php

declare(strict_types=1);

namespace App\Modules\Jabatan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JabatanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jabatan'    => $this->id_jabatan,
            'id_perusahaan' => $this->id_perusahaan,
            'id_departemen' => $this->id_departemen,
            'id_peran'      => $this->id_peran,
            'kode_jabatan'  => $this->kode_jabatan,
            'nama_jabatan'  => $this->nama_jabatan,
            'level'         => (int) $this->level,
            'aktif'         => (bool) $this->aktif,
            'dibuat_pada'   => $this->dibuat_pada,
            'diubah_pada'   => $this->diubah_pada,
        ];
    }
}
