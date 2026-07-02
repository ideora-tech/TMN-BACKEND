<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KaryawanExitResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_exit'                => $this->id_exit,
            'id_perusahaan'          => $this->id_perusahaan,
            'id_karyawan'            => $this->id_karyawan,
            'jenis_exit'             => $this->jenis_exit,
            'tanggal_efektif'        => $this->tanggal_efektif,
            'alasan'                 => $this->alasan,
            'dapat_direkrut_kembali' => (bool) $this->dapat_direkrut_kembali,
            'dibuat_pada'            => $this->dibuat_pada,
            'diubah_pada'            => $this->diubah_pada,
        ];
    }
}
