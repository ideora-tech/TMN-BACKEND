<?php

declare(strict_types=1);

namespace App\Modules\Barang\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KategoriBarangResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_kategori_barang' => $this->id_kategori_barang,
            'nama'               => $this->nama,
            'keterangan'         => $this->keterangan,
            'aktif'              => (bool) $this->aktif,
        ];
    }
}
