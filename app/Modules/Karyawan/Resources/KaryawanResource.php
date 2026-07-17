<?php

declare(strict_types=1);

namespace App\Modules\Karyawan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KaryawanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_karyawan'        => $this->id_karyawan,
            'id_perusahaan'      => $this->id_perusahaan,
            'nik'                => $this->nik,
            'nama_karyawan'      => $this->nama_karyawan,
            'email'              => $this->email,
            'telepon'            => $this->telepon,
            'jenis_kelamin'      => $this->jenis_kelamin,
            'tanggal_lahir'      => $this->tanggal_lahir,
            'tanggal_masuk'      => $this->tanggal_masuk,
            'status_kepegawaian' => $this->status_kepegawaian,
            'gaji_pokok'         => (float) $this->gaji_pokok,
            'aktif'              => (bool) $this->aktif,
            'jabatan'            => $this->jabatan_nama !== null ? [
                'id_jabatan'   => $this->id_jabatan,
                'nama_jabatan' => $this->jabatan_nama,
            ] : null,
            'lokasi'             => $this->lokasi_nama !== null ? [
                'id_lokasi'   => $this->id_lokasi,
                'nama_lokasi' => $this->lokasi_nama,
            ] : null,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
