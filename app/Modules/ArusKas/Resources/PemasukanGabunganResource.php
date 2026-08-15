<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class PemasukanGabunganResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'jenis'        => $this->jenis,
            'id'           => $this->id,
            'nomor'        => $this->nomor,
            'kategori'     => $this->kategori,
            'tanggal'      => $this->tanggal,
            'nominal'      => (float) $this->nominal,
            'sumber_dana'  => $this->sumber_dana,
            'keterangan'   => $this->keterangan,
            'url_bukti'    => PenyimpananBerkas::url($this->url_bukti),
            'dapat_diubah' => $this->jenis === 'manual',
        ];
    }
}
