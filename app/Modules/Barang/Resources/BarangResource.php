<?php

declare(strict_types=1);

namespace App\Modules\Barang\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BarangResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_barang'          => $this->id_barang,
            'kode'               => $this->kode,
            'nama'               => $this->nama,
            'id_kategori_barang' => $this->id_kategori_barang,
            'nama_kategori'      => $this->nama_kategori ?? null,
            'satuan'             => $this->satuan,
            'harga_standar'      => (float) $this->harga_standar,
            'stok'               => (int) $this->stok,
            'stok_minimum'       => (int) $this->stok_minimum,
            'stok_menipis'       => (int) $this->stok_minimum > 0 && (int) $this->stok < (int) $this->stok_minimum,
            'aktif'              => (bool) $this->aktif,
            'dibuat_pada'        => $this->dibuat_pada,
        ];
    }
}
