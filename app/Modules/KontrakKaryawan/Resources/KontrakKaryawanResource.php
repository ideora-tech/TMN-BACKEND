<?php

declare(strict_types=1);

namespace App\Modules\KontrakKaryawan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KontrakKaryawanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_kontrak'      => $this->id_kontrak,
            'id_karyawan'     => $this->id_karyawan,
            'jenis_kontrak'   => $this->jenis_kontrak,
            'nomor_kontrak'   => $this->nomor_kontrak,
            'tanggal_mulai'   => $this->tanggal_mulai,
            'tanggal_selesai' => $this->tanggal_selesai,
            'keterangan'      => $this->keterangan,
            'aktif'           => $this->tanggal_selesai === null || $this->tanggal_selesai >= now()->toDateString(),
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
