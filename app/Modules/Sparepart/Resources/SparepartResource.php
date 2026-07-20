<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SparepartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_sparepart'           => $this->id_sparepart,
            'id_perusahaan'          => $this->id_perusahaan,
            'kode'                   => $this->kode,
            'nama'                   => $this->nama,
            'id_kategori_sparepart'  => $this->id_kategori_sparepart,
            'nama_kategori_sparepart' => $this->nama_kategori_sparepart ?? null,
            'satuan'                 => $this->satuan,
            'harga_standar'          => (float) $this->harga_standar,
            'stok'                   => (int) $this->stok,
            'aktif'                  => (bool) $this->aktif,
            'dibuat_pada'            => $this->dibuat_pada,
            'diubah_pada'            => $this->diubah_pada,
        ];
    }
}
