<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_vendor'         => $this->id_vendor,
            'id_perusahaan'     => $this->id_perusahaan,
            'kode_vendor'       => $this->kode_vendor,
            'nama_vendor'       => $this->nama_vendor,
            'jenis_vendor'      => $this->jenis_vendor,
            'pic_nama'          => $this->pic_nama,
            'email'             => $this->email,
            'telepon'           => $this->telepon,
            'alamat'            => $this->alamat,
            'npwp'              => $this->npwp,
            'tanggal_bergabung' => $this->tanggal_bergabung?->toDateString(),
            'aktif'             => (bool) $this->aktif,
            'jumlah_unit'               => (int) ($this->jumlah_unit ?? 0),
            'jumlah_driver'             => (int) ($this->jumlah_driver ?? 0),
            'jumlah_kontrak_aktif'      => (int) ($this->jumlah_kontrak_aktif ?? 0),
            'nilai_kontrak_aktif'       => (float) ($this->nilai_kontrak_aktif ?? 0),
            'kontrak_berakhir_terdekat' => $this->kontrak_berakhir_terdekat ?? null,
            'dibuat_pada'       => $this->dibuat_pada,
            'diubah_pada'       => $this->diubah_pada,
        ];
    }
}
