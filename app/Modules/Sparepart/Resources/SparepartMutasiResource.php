<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SparepartMutasiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_mutasi'    => $this->id_mutasi,
            'id_sparepart' => $this->id_sparepart,
            'jenis'        => $this->jenis,
            'qty'          => (int) $this->qty,
            'harga'        => $this->harga !== null ? (float) $this->harga : null,
            'id_perawatan' => $this->id_perawatan,
            'keterangan'   => $this->keterangan,
            'tanggal'      => $this->tanggal,
            'dibuat_pada'  => $this->dibuat_pada,
        ];
    }
}
