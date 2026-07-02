<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StatusTripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_status'   => $this->id_status,
            'id_trip'     => $this->id_trip,
            'status'      => $this->status,
            'keterangan'  => $this->keterangan,
            'latitude'    => $this->latitude,
            'longitude'   => $this->longitude,
            'dibuat_oleh' => $this->dibuat_oleh,
            'dibuat_pada' => $this->dibuat_pada,
        ];
    }
}
