<?php
declare(strict_types=1);
namespace App\Modules\Supir\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupirResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_supir'            => $this->id_supir,
            'id_pengguna'         => $this->id_pengguna,
            'id_perusahaan'       => $this->id_perusahaan,
            'nama'                => $this->nama,
            'no_sim'              => $this->no_sim,
            'jenis_sim'           => $this->jenis_sim,
            'tgl_kadaluarsa_sim'  => $this->tgl_kadaluarsa_sim,
            'telepon'             => $this->telepon,
            'status'              => $this->status,
            'foto'                => $this->foto,
            'id_armada_default'   => $this->id_armada_default,
            'dibuat_pada'         => $this->dibuat_pada,
            'diubah_pada'         => $this->diubah_pada,
        ];
    }
}
