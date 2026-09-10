<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class DokumenArmadaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_dokumen_armada'     => $this->id_dokumen_armada,
            'id_armada'             => $this->id_armada,
            'armada_nopol'          => $this->armada_nopol ?? null,
            'armada_merk'           => $this->armada_merk ?? null,
            'jenis_dokumen'         => $this->jenis_dokumen,
            'nomor'                 => $this->nomor,
            'berlaku_sampai'        => $this->berlaku_sampai,
            'url_file'              => PenyimpananBerkas::url($this->url_file),
            'aktif'                 => (bool) ($this->aktif ?? 1),
            'id_dokumen_sebelumnya' => $this->id_dokumen_sebelumnya ?? null,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
            'riwayat'               => $this->when(
                property_exists($this->resource, 'riwayat'),
                fn () => DokumenArmadaResource::collection($this->resource->riwayat),
            ),
            'id_dokumen_pengganti'  => $this->when(
                property_exists($this->resource, 'id_dokumen_pengganti'),
                fn () => $this->resource->id_dokumen_pengganti,
            ),
        ];
    }
}
