<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JabatanRepository implements JabatanRepositoryInterface
{
    private const COLUMNS = [
        'id_jabatan', 'id_perusahaan', 'id_departemen', 'id_peran', 'kode_jabatan', 'nama_jabatan', 'level', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idDepartemen = null): LengthAwarePaginator
    {
        $query = DB::table('jabatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('level')
            ->orderBy('nama_jabatan');

        if ($idDepartemen !== null) {
            $query->where('id_departemen', $idDepartemen);
        }

        return $query->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('jabatan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_jabatan', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jabatan');
        DB::table('jabatan')->insert($data);
        return $this->findById($data['id_jabatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('jabatan')
            ->where('id_jabatan', $record->id_jabatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_jabatan);
    }

    public function delete(object $record): void
    {
        DB::table('jabatan')
            ->where('id_jabatan', $record->id_jabatan)
            ->update(RecordHelper::stampDelete());
    }
}
