<?php

declare(strict_types=1);

namespace App\Modules\LampiranMaster\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class LampiranMasterResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_lampiran' => $this->id_lampiran,
            'nama_asli'   => $this->nama_asli,
            'url_file'    => PenyimpananBerkas::url($this->url_file),
            'tipe_mime'   => $this->tipe_mime,
            'ukuran'      => (int) $this->ukuran,
            'gambar'      => str_starts_with((string) $this->tipe_mime, 'image/'),
            'dibuat_pada' => $this->dibuat_pada,
        ];
    }
}
