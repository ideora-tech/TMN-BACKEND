<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class PenawaranLampiranResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_lampiran' => $this->id_lampiran,
            'url_file'    => PenyimpananBerkas::url($this->url_file),
            'nama_asli'   => $this->nama_asli,
        ];
    }
}
