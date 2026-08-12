<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LaporanProyekResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_laporan'         => $this->id_laporan,
            'id_proyek'          => $this->id_proyek,
            'kode_proyek'        => $this->whenNotNull($this->kode_proyek ?? null),
            'nama_proyek'        => $this->whenNotNull($this->nama_proyek ?? null),
            'nama_klien'         => $this->nama_klien ?? null,
            'ringkasan'          => $this->ringkasan,
            'total_trip'         => $this->total_trip,
            'total_trip_aktual'  => $this->whenNotNull($this->total_trip_aktual ?? null),
            'id_diserahkan_oleh' => $this->id_diserahkan_oleh,
            'diserahkan_pada'    => $this->diserahkan_pada,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
