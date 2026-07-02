<?php

declare(strict_types=1);

namespace App\Modules\Armada\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ArmadaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_armada'          => $this->id_armada,
            'id_perusahaan'      => $this->id_perusahaan,
            'id_jenis_kendaraan' => $this->id_jenis_kendaraan,
            'id_vendor'          => $this->id_vendor,
            'nopol'              => $this->nopol,
            'merk'               => $this->merk,
            'model'              => $this->model,
            'tahun'              => $this->tahun !== null ? (int) $this->tahun : null,
            'kepemilikan'        => $this->kepemilikan,
            'status'             => $this->status,
            'aktif'              => (bool) $this->aktif,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
