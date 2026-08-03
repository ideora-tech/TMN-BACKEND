<?php
declare(strict_types=1);

namespace App\Modules\Supplier\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_supplier'   => $this->id_supplier,
            'id_perusahaan' => $this->id_perusahaan,
            'nama'          => $this->nama,
            'telepon'       => $this->telepon,
            'alamat'        => $this->alamat,
            'aktif'         => (bool) $this->aktif,
            'dibuat_pada'   => $this->dibuat_pada,
            'diubah_pada'   => $this->diubah_pada,
        ];
    }
}
