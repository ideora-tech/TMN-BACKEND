<?php

declare(strict_types=1);

namespace App\Modules\Shift;

use App\Modules\Shift\Contracts\ShiftRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ShiftRepository implements ShiftRepositoryInterface
{
    private const COLUMNS = [
        'id_shift', 'id_perusahaan', 'nama', 'jam_mulai', 'jam_selesai', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('shift')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('jam_mulai')
            ->orderBy('nama')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('shift')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_shift', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_shift');
        DB::table('shift')->insert($data);
        return $this->findById($data['id_shift']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('shift')
            ->where('id_shift', $record->id_shift)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_shift);
    }

    public function delete(object $record): void
    {
        DB::table('shift')
            ->where('id_shift', $record->id_shift)
            ->update(RecordHelper::stampDelete());
    }

    public function countActiveUsage(string $idShift): int
    {
        return DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where('id_shift', $idShift)
            ->count();
    }
}
