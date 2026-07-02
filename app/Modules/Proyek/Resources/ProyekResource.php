<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProyekResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_proyek'       => $this->id_proyek,
            'id_perusahaan'   => $this->id_perusahaan,
            'id_klien'        => $this->id_klien,
            'kode_proyek'     => $this->kode_proyek,
            'nama_proyek'     => $this->nama_proyek,
            'tanggal_mulai'   => $this->tanggal_mulai,
            'tanggal_selesai' => $this->tanggal_selesai,
            'status'          => $this->status,
            'keterangan'      => $this->keterangan,
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
