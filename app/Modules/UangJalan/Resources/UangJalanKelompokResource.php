<?php

declare(strict_types=1);

namespace App\Modules\UangJalan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UangJalanKelompokResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'kunci'         => $this->tipe_driver . '|' . $this->kunci_driver . '|' . $this->kunci_proyek,
            'tipe_driver'   => $this->tipe_driver,
            'kunci_driver'  => $this->kunci_driver,
            'id_proyek'     => $this->kunci_proyek !== '' ? $this->kunci_proyek : null,
            'nama_driver'   => $this->nama_driver,
            'nama_vendor'   => $this->nama_vendor,
            'kode_proyek'   => $this->kode_proyek,
            'nama_proyek'   => $this->nama_proyek,
            'jumlah'        => (int) $this->jumlah,
            'total_trip'    => (int) $this->total_trip,
            'total_nominal' => (float) $this->total_nominal,
            'tanggal_awal'  => $this->tanggal_awal,
            'tanggal_akhir' => $this->tanggal_akhir,
            'jumlah_nopol'  => (int) $this->jumlah_nopol,
            'nopol'         => $this->nopol,
            'jumlah_rute'   => (int) $this->jumlah_rute,
            'rute'          => $this->rute,
            'status'        => (object) $this->status,
            'tunggal'       => $this->tunggal !== null ? new UangJalanResource($this->tunggal) : null,
        ];
    }
}
