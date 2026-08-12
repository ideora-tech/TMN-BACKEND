<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ArmadaVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_armada_vendor'  => $this->id_armada_vendor,
            'id_vendor'         => $this->id_vendor,
            'nopol'             => $this->nopol,
            'merk'              => $this->merk,
            'jenis'             => $this->jenis,
            'id_jenis_kendaraan'   => $this->id_jenis_kendaraan,
            'nama_jenis_kendaraan' => $this->whenNotNull($this->nama_jenis_kendaraan ?? null),
            'kapasitas'         => $this->kapasitas,
            'tahun'             => $this->tahun,
            'masa_berlaku_stnk' => $this->masa_berlaku_stnk?->toDateString(),
            'masa_berlaku_kir'  => $this->masa_berlaku_kir?->toDateString(),
            'aktif'             => (bool) $this->aktif,
            'nama_vendor'      => $this->whenNotNull($this->nama_vendor ?? null),
            'dibuat_pada'      => $this->dibuat_pada,
            'diubah_pada'      => $this->diubah_pada,
        ];
    }
}
