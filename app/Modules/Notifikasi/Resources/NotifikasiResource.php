<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NotifikasiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_notifikasi'  => $this->id_notifikasi,
            'id_perusahaan'  => $this->id_perusahaan,
            'id_pengguna'    => $this->id_pengguna,
            'judul'          => $this->judul,
            'isi'            => $this->isi,
            'tipe'           => $this->tipe,
            'referensi_id'   => $this->referensi_id,
            'referensi_tipe' => $this->referensi_tipe,
            'dibaca'         => (bool) $this->dibaca,
            'dibaca_pada'    => $this->dibaca_pada,
            'dibuat_pada'    => $this->dibuat_pada,
        ];
    }
}