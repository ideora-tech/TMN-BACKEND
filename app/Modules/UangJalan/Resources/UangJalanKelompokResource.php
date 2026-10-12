<?php

declare(strict_types=1);

namespace App\Modules\UangJalan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UangJalanKelompokResource extends JsonResource
{
    public function toArray($request): array
    {
        $idProyek = $this->kunci_proyek !== '' ? $this->kunci_proyek : null;

        return [
            'kunci'         => $idProyek ?? 'tanpa-proyek',
            'id_proyek'     => $idProyek,
            'kode_proyek'   => $this->kode_proyek,
            'nama_proyek'   => $this->nama_proyek,
            'jumlah'        => (int) $this->jumlah,
            'jumlah_driver' => (int) $this->jumlah_driver,
            'total_trip'    => (int) $this->total_trip,
            'total_nominal' => (float) $this->total_nominal,
            'tanggal_awal'  => $this->tanggal_awal,
            'tanggal_akhir' => $this->tanggal_akhir,
            'status'        => (object) $this->status,
            'tunggal'       => $this->tunggal !== null ? new UangJalanResource($this->tunggal) : null,
        ];
    }
}
