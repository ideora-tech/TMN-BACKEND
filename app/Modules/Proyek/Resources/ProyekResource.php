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
            'nama_klien'      => $this->nama_klien ?? null,
            'kode_proyek'     => $this->kode_proyek,
            'nama_proyek'     => $this->nama_proyek,
            'tanggal_mulai'   => $this->tanggal_mulai,
            'tanggal_selesai' => $this->tanggal_selesai,
            'harga_penawaran' => $this->harga_penawaran !== null ? (float) $this->harga_penawaran : null,
            'harga_proyek'    => $this->harga_proyek !== null ? (float) $this->harga_proyek : null,
            'status'          => $this->status,
            'tipe_harga'      => $this->tipe_harga,
            'keterangan'      => $this->keterangan,
            'realisasi'       => $this->realisasi ?? null,
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
