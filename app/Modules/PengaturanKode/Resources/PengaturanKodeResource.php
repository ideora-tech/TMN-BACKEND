<?php

declare(strict_types=1);

namespace App\Modules\PengaturanKode\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PengaturanKodeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'entitas'       => $this->entitas,
            'prefix'        => $this->prefix,
            'panjang_digit' => (int) $this->panjang_digit,
            'reset'         => $this->reset,
            'tersimpan'     => (bool) $this->tersimpan,
        ];
    }
}
