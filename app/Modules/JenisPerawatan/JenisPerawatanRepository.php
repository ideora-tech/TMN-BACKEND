<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Modules\JenisPerawatan\Contracts\JenisPerawatanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JenisPerawatanRepository implements JenisPerawatanRepositoryInterface
{
    private const COLUMNS = [
        'id_jenis_perawatan', 'id_perusahaan', 'nama', 'keterangan', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('jenis_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('jenis_perawatan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_jenis_perawatan', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jenis_perawatan');
        DB::table('jenis_perawatan')->insert($data);
        return $this->findById($data['id_jenis_perawatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('jenis_perawatan')
            ->where('id_jenis_perawatan', $record->id_jenis_perawatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_jenis_perawatan);
    }

    public function delete(object $record): void
    {
        DB::table('jenis_perawatan')
            ->where('id_jenis_perawatan', $record->id_jenis_perawatan)
            ->update(RecordHelper::stampDelete());
    }

    public function countActiveUsage(string $idJenisPerawatan): int
    {
        return DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->where('id_jenis_perawatan', $idJenisPerawatan)
            ->count();
    }
}
