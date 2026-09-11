<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DokumenPerUnitResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_armada'            => $this->resource['id_armada'],
            'nopol'                => $this->resource['nopol'],
            'merk'                 => $this->resource['merk'],
            'nama_jenis_kendaraan' => $this->resource['nama_jenis_kendaraan'],
            'status_armada'        => $this->resource['status_armada'],
            'kondisi'              => $this->resource['kondisi'],
            'jumlah_dokumen'       => $this->resource['jumlah_dokumen'],
            'terdekat'             => $this->resource['terdekat'],
            'dokumen'              => DokumenArmadaResource::collection($this->resource['dokumen'])->resolve($request),
        ];
    }
}
