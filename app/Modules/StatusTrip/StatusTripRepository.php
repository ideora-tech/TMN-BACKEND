<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Modules\StatusTrip\Contracts\StatusTripRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StatusTripRepository implements StatusTripRepositoryInterface
{
    private const COLUMNS = [
        'id_status', 'id_trip', 'status', 'keterangan', 'latitude', 'longitude', 'dibuat_oleh', 'dibuat_pada',
    ];

    public function listByTrip(string $idTrip): Collection
    {
        return DB::table('status_trip')
            ->select(self::COLUMNS)
            ->where('id_trip', $idTrip)
            ->orderBy('dibuat_pada', 'desc')
            ->get();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_status');
        DB::table('status_trip')->insert($data);
        return DB::table('status_trip')->select(self::COLUMNS)->where('id_status', $data['id_status'])->first();
    }
}
