<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProyekUnitResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_proyek_unit'   => $this->id_proyek_unit,
            'sumber'           => $this->sumber,
            'id_armada'        => $this->id_armada,
            'id_armada_vendor' => $this->id_armada_vendor,
            'nopol'            => $this->nopol,
            'merk'             => $this->merk,
            'nama_jenis'       => $this->nama_jenis,
            'nama_vendor'      => $this->nama_vendor,
            'nama_supir'       => $this->sumber === 'vendor' ? $this->nama_supir_vendor : $this->nama_supir_internal,
        ];
    }
}
