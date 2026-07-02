<?php

declare(strict_types=1);

namespace App\Modules\Klien\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KlienResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_klien'    => $this->id_klien,
            'id_perusahaan' => $this->id_perusahaan,
            'kode_klien'  => $this->kode_klien,
            'nama_klien'  => $this->nama_klien,
            'email'       => $this->email,
            'telepon'     => $this->telepon,
            'alamat'      => $this->alamat,
            'kontak_pic'  => $this->kontak_pic,
            'aktif'       => (bool) $this->aktif,
            'dibuat_pada' => $this->dibuat_pada,
            'diubah_pada' => $this->diubah_pada,
        ];
    }
}
