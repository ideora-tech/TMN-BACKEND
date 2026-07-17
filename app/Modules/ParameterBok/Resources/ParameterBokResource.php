<?php

declare(strict_types=1);

namespace App\Modules\ParameterBok\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ParameterBokResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_parameter_bok'       => $this->id_parameter_bok,
            'id_perusahaan'          => $this->id_perusahaan,
            'id_jenis_kendaraan'     => $this->id_jenis_kendaraan,
            'id_jenis_bbm'           => $this->id_jenis_bbm,
            'nama_jenis'             => $this->nama_jenis ?? null,
            'nama_bbm'               => $this->nama_bbm ?? null,
            'konsumsi_km_per_liter'  => (float) $this->konsumsi_km_per_liter,
            'biaya_ban_per_km'       => (float) $this->biaya_ban_per_km,
            'biaya_servis_per_km'    => (float) $this->biaya_servis_per_km,
            'biaya_tetap_bulanan'    => (float) $this->biaya_tetap_bulanan,
            'utilisasi_km_per_bulan' => (float) $this->utilisasi_km_per_bulan,
            'margin_persen'          => (float) $this->margin_persen,
            'keterangan'             => $this->keterangan,
            'aktif'                  => (bool) $this->aktif,
            'dibuat_pada'            => $this->dibuat_pada,
            'diubah_pada'            => $this->diubah_pada,
        ];
    }
}
