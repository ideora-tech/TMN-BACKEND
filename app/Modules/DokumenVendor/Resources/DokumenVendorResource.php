<?php

declare(strict_types=1);

namespace App\Modules\DokumenVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DokumenVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_dokumen_vendor' => $this->id_dokumen_vendor,
            'id_vendor'         => $this->id_vendor,
            'jenis_dokumen'     => $this->jenis_dokumen,
            'nomor'             => $this->nomor,
            'berlaku_sampai'    => $this->berlaku_sampai?->toDateString(),
            'url_file'          => $this->url_file,
            'dibuat_pada'       => $this->dibuat_pada,
            'diubah_pada'       => $this->diubah_pada,
        ];
    }
}
