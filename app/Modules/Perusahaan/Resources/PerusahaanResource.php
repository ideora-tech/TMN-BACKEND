<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PerusahaanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_perusahaan' => $this->id_perusahaan,
            'nama'          => $this->nama,
            'email'         => $this->email,
            'telepon'       => $this->telepon,
            'alamat'        => $this->alamat,
            'nama_bank'          => $this->nama_bank,
            'atas_nama_rekening' => $this->atas_nama_rekening,
            'nomor_rekening'     => $this->nomor_rekening,
            'id_zona'       => $this->id_zona,
            'id_mata_uang'  => $this->id_mata_uang,
            'aktif'         => (bool) $this->aktif,
            'dibuat_pada'   => $this->dibuat_pada,
        ];
    }
}
