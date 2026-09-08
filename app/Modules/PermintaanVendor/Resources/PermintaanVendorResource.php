<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PermintaanVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_permintaan'        => $this->id_permintaan,
            'id_perusahaan'        => $this->id_perusahaan,
            'nomor_permintaan'     => $this->nomor_permintaan,
            'id_proyek'            => $this->id_proyek,
            'nama_proyek'          => $this->nama_proyek ?? null,
            'id_jenis_kendaraan'   => $this->id_jenis_kendaraan,
            'nama_jenis_kendaraan' => $this->nama_jenis_kendaraan ?? null,
            'jumlah_unit'          => (int) $this->jumlah_unit,
            'unit_diminta'         => $this->unit_diminta ?? [],
            'mekanisme'            => $this->mekanisme,
            'periode_dari'         => $this->periode_dari,
            'periode_sampai'       => $this->periode_sampai,
            'catatan'              => $this->catatan,
            'status'               => $this->status,
            'alasan_ditolak'       => $this->alasan_ditolak,
            'id_kontrak_vendor'    => $this->id_kontrak_vendor,
            'nomor_kontrak'        => $this->nomor_kontrak ?? null,
            'dibuat_pada'          => $this->dibuat_pada,
            'diubah_pada'          => $this->diubah_pada,
        ];
    }
}
