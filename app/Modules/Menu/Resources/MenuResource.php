<?php

declare(strict_types=1);

namespace App\Modules\Menu\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MenuResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_menu'       => $this->id_menu,
            'nama_menu'     => $this->nama_menu,
            'path'          => $this->path,
            'id_menu_induk' => $this->id_menu_induk,
            'icon'          => $this->icon,
            'urutan'        => (int) $this->urutan,
            'aktif'         => (bool) $this->aktif,
            'children'      => $this->when(
                isset($this->resource->children),
                fn () => MenuResource::collection($this->resource->children)
            ),
            'dibuat_pada'   => $this->dibuat_pada,
            'diubah_pada'   => $this->diubah_pada,
        ];
    }
}
