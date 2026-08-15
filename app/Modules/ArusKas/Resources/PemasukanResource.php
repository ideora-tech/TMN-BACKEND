<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class PemasukanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_pemasukan'    => $this->id_pemasukan,
            'id_perusahaan'   => $this->id_perusahaan,
            'nomor_pemasukan' => $this->nomor_pemasukan,
            'kategori'        => $this->kategori,
            'tanggal'         => $this->tanggal,
            'nominal'         => (float) $this->nominal,
            'sumber_dana'     => $this->sumber_dana,
            'keterangan'      => $this->keterangan,
            'url_bukti'       => PenyimpananBerkas::url($this->url_bukti),
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
