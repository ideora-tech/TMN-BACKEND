<?php

declare(strict_types=1);

namespace App\Modules\RekeningPerusahaan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RekeningPerusahaanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_rekening_perusahaan' => $this->id_rekening_perusahaan,
            'id_perusahaan'          => $this->id_perusahaan,
            'nama_bank'              => $this->nama_bank,
            'nomor_rekening'         => $this->nomor_rekening,
            'atas_nama_rekening'     => $this->atas_nama_rekening,
            'utama'                  => (bool) $this->utama,
            'dibuat_pada'            => $this->dibuat_pada,
            'diubah_pada'            => $this->diubah_pada,
        ];
    }
}
