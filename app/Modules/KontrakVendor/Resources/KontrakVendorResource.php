<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KontrakVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_kontrak_vendor' => $this->id_kontrak_vendor,
            'id_perusahaan'     => $this->id_perusahaan,
            'id_vendor'         => $this->id_vendor,
            'id_proyek'         => $this->id_proyek,
            'mekanisme'         => $this->mekanisme,
            'nilai_kontrak'     => (float) $this->nilai_kontrak,
            'tanggal_mulai'     => $this->tanggal_mulai,
            'tanggal_selesai'   => $this->tanggal_selesai,
            'status'            => $this->status,
            'dibuat_pada'       => $this->dibuat_pada,
            'diubah_pada'       => $this->diubah_pada,
        ];
    }
}
