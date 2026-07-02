<?php

declare(strict_types=1);

namespace App\Modules\Departemen\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DepartemenResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_departemen'      => $this->id_departemen,
            'id_perusahaan'      => $this->id_perusahaan,
            'id_departemen_induk'=> $this->id_departemen_induk,
            'kode_departemen'    => $this->kode_departemen,
            'nama_departemen'    => $this->nama_departemen,
            'aktif'              => (bool) $this->aktif,
            'children'           => $this->when(
                isset($this->resource->children),
                fn () => DepartemenResource::collection($this->resource->children)
            ),
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
