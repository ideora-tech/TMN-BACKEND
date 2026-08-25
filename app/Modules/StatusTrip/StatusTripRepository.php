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

    private function baseQuery()
    {
        return DB::table('status_trip as st')
            ->leftJoin('pengguna as pg', 'pg.id_pengguna', '=', 'st.dibuat_oleh')
            ->leftJoin('karyawan as k', 'k.id_karyawan', '=', 'pg.id_karyawan')
            ->select(array_merge(
                array_map(fn ($kolom) => "st.$kolom", self::COLUMNS),
                [
                    DB::raw('COALESCE(k.nama_karyawan, pg.username) as dibuat_oleh_nama'),
                    'pg.kode_peran as dibuat_oleh_peran',
                ]
            ));
    }

    public function listByTrip(string $idTrip): Collection
    {
        return $this->baseQuery()
            ->where('st.id_trip', $idTrip)
            ->orderBy('st.dibuat_pada', 'desc')
            ->get();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_status');
        DB::table('status_trip')->insert($data);
        return $this->baseQuery()->where('st.id_status', $data['id_status'])->first();
    }
}
