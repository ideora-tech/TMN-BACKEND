<?php
// app/Modules/KategoriSparepart/Resources/KategoriSparepartResource.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KategoriSparepartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_kategori_sparepart' => $this->id_kategori_sparepart,
            'id_perusahaan'         => $this->id_perusahaan,
            'nama'                  => $this->nama,
            'keterangan'            => $this->keterangan,
            'aktif'                 => (bool) $this->aktif,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
        ];
    }
}
