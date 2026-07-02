<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class StatusTripModel extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $table = 'status_trip';
    protected $primaryKey = 'id_status';

    protected $fillable = [
        'id_status',
        'id_trip',
        'status',
        'keterangan',
        'latitude',
        'longitude',
        'dibuat_oleh',
        'dibuat_pada',
    ];

    protected $casts = [
        'latitude'    => 'float',
        'longitude'   => 'float',
        'dibuat_pada' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->dibuat_pada)) {
                $model->dibuat_pada = now();
            }
            if (empty($model->dibuat_oleh) && auth()->check()) {
                $model->dibuat_oleh = auth()->id();
            }
        });
    }
}
