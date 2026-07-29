<?php

declare(strict_types=1);

namespace App\Modules\RekeningVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RekeningVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_rekening_vendor' => $this->id_rekening_vendor,
            'id_vendor'          => $this->id_vendor,
            'nama_bank'          => $this->nama_bank,
            'nomor_rekening'     => $this->nomor_rekening,
            'atas_nama'          => $this->atas_nama,
            'cabang'             => $this->cabang,
            'mata_uang'          => $this->mata_uang,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
