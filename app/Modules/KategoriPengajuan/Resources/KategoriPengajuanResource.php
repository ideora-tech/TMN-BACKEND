<?php

declare(strict_types=1);

namespace App\Modules\KategoriPengajuan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KategoriPengajuanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_kategori_pengajuan' => $this->id_kategori_pengajuan,
            'kode'                  => $this->kode,
            'nama'                  => $this->nama,
            'sistem'                => (bool) $this->sistem,
            'aktif'                 => (bool) $this->aktif,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
        ];
    }
}
