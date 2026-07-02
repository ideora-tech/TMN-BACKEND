<?php

declare(strict_types=1);

namespace App\Modules\Modul\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ModulResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_modul'   => $this->id_modul,
            'kode_modul' => $this->kode_modul,
            'nama_modul' => $this->nama_modul,
            'urutan'     => (int) $this->urutan,
            'aktif'      => (bool) $this->aktif,
            'dibuat_pada' => $this->dibuat_pada,
            'diubah_pada' => $this->diubah_pada,
        ];
    }
}
