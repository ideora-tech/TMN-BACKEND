<?php

declare(strict_types=1);

namespace App\Modules\TipePermintaan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TipePermintaanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_tipe_permintaan' => $this->id_tipe_permintaan,
            'id_perusahaan'      => $this->id_perusahaan,
            'nama_tipe'          => $this->nama_tipe,
            'jenis_form'         => $this->jenis_form,
            'aktif'              => (bool) $this->aktif,
            'jumlah_judul'       => isset($this->jumlah_judul) ? (int) $this->jumlah_judul : null,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
