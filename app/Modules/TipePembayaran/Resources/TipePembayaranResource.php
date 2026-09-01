<?php

declare(strict_types=1);

namespace App\Modules\TipePembayaran\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TipePembayaranResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_tipe_pembayaran' => $this->id_tipe_pembayaran,
            'id_perusahaan'      => $this->id_perusahaan,
            'kode_tipe'          => $this->kode_tipe,
            'nama_tipe'          => $this->nama_tipe,
            'aktif'              => (bool) $this->aktif,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
