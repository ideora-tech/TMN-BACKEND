<?php

declare(strict_types=1);

namespace App\Modules\LogError\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LogErrorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_log_error' => $this->id_log_error,
            'level'        => $this->level,
            'pesan'        => $this->pesan,
            'stack_trace'  => $this->stack_trace,
            'metode_http'  => $this->metode_http,
            'jalur'        => $this->jalur,
            'kode_status'  => $this->kode_status,
            'id_pengguna'  => $this->id_pengguna,
            'dibuat_pada'  => $this->dibuat_pada,
        ];
    }
}
