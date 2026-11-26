<?php

declare(strict_types=1);

namespace App\Modules\KategoriPemasukan\Resources;

use App\Modules\KategoriPemasukan\KategoriPemasukanService;
use Illuminate\Http\Resources\Json\JsonResource;

class KategoriPemasukanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_kategori_pemasukan' => $this->id_kategori_pemasukan,
            'kode'                  => $this->kode,
            'nama'                  => $this->nama,
            'pos_psak'              => $this->pos_psak,
            'label_pos_psak'        => KategoriPemasukanService::POS_PSAK[$this->pos_psak] ?? $this->pos_psak,
            'sistem'                => (bool) $this->sistem,
            'aktif'                 => (bool) $this->aktif,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
        ];
    }
}
