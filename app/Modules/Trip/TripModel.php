<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Models\BaseModel;

class TripModel extends BaseModel
{
    protected $table = 'trip';
    protected $primaryKey = 'id_trip';

    protected $fillable = [
        'id_trip',
        'id_jadwal',
        'waktu_checkin',
        'waktu_checkout',
        'status',
        'catatan',
    ];

    protected $casts = [
        'waktu_checkin'  => 'datetime',
        'waktu_checkout' => 'datetime',
    ];
}
