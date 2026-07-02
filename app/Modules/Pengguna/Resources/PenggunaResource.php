<?php

declare(strict_types=1);

namespace App\Modules\Pengguna\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PenggunaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_pengguna'         => $this->id_pengguna,
            'id_perusahaan'       => $this->id_perusahaan,
            'id_peran'            => $this->id_peran,
            'id_karyawan'         => $this->id_karyawan,
            'username'            => $this->username,
            'email'               => $this->email,
            'aktif'               => (bool) $this->aktif,
            'harus_ganti_password' => (bool) $this->harus_ganti_password,
            'login_terakhir'      => $this->login_terakhir,
            'dibuat_pada'         => $this->dibuat_pada,
            'diubah_pada'         => $this->diubah_pada,
        ];
    }
}
