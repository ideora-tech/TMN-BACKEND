<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class TransaksiArusKasResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'tanggal'      => $this->tanggal,
            'arah'         => $this->arah,
            'sumber'       => $this->sumber,
            'kategori'     => $this->kategori,
            'nominal'      => (float) $this->nominal,
            'referensi'    => [
                'id'    => $this->id_referensi,
                'label' => $this->label,
            ],
            'keterangan'   => $this->keterangan,
            'url_bukti'    => PenyimpananBerkas::url($this->url_bukti),
            'dapat_diubah' => false,
        ];
    }
}
