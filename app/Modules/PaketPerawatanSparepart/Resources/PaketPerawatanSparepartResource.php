<?php
// app/Modules/PaketPerawatanSparepart/Resources/PaketPerawatanSparepartResource.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaketPerawatanSparepartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_paket_perawatan_sparepart' => $this->id_paket_perawatan_sparepart,
            'id_perusahaan'                => $this->id_perusahaan,
            'id_jenis_perawatan'           => $this->id_jenis_perawatan,
            'id_jenis_kendaraan'           => $this->id_jenis_kendaraan,
            'id_sparepart'                 => $this->id_sparepart,
            'nama_jenis_perawatan'         => $this->nama_jenis_perawatan ?? null,
            'nama_jenis_kendaraan'         => $this->nama_jenis_kendaraan ?? null,
            'nama_sparepart'               => $this->nama_sparepart ?? null,
            'satuan_sparepart'             => $this->satuan_sparepart ?? null,
            'qty_standar'                  => (int) $this->qty_standar,
            'aktif'                        => (bool) $this->aktif,
            'dibuat_pada'                  => $this->dibuat_pada,
            'diubah_pada'                  => $this->diubah_pada,
        ];
    }
}
