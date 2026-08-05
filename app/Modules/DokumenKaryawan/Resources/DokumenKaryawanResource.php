<?php

declare(strict_types=1);

namespace App\Modules\DokumenKaryawan\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class DokumenKaryawanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_dokumen_karyawan' => $this->id_dokumen_karyawan,
            'id_karyawan'         => $this->id_karyawan,
            'jenis_dokumen'       => $this->jenis_dokumen,
            'nomor'               => $this->nomor,
            'berlaku_sampai'      => $this->berlaku_sampai,
            'url_file'            => PenyimpananBerkas::url($this->url_file),
            'karyawan_nama'       => $this->karyawan_nama ?? null,
            'karyawan_nik'        => $this->karyawan_nik ?? null,
            'dibuat_pada'         => $this->dibuat_pada,
            'diubah_pada'         => $this->diubah_pada,
        ];
    }
}
