<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Modules\Departemen\Contracts\DepartemenRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepartemenRepository implements DepartemenRepositoryInterface
{
    private const COLUMNS = [
        'id_departemen', 'id_perusahaan', 'id_departemen_induk', 'kode_departemen', 'nama_departemen', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('departemen')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_departemen')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function tree(string $idPerusahaan): array
    {
        $all = DB::table('departemen')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_departemen')
            ->get();

        return $this->buildTree($all, null);
    }

    public function findById(string $id): ?object
    {
        return DB::table('departemen')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_departemen', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_departemen');
        DB::table('departemen')->insert($data);
        return $this->findById($data['id_departemen']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('departemen')
            ->where('id_departemen', $record->id_departemen)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_departemen);
    }

    public function delete(object $record): void
    {
        DB::table('departemen')
            ->where('id_departemen', $record->id_departemen)
            ->update(RecordHelper::stampDelete());
    }

    private function buildTree(Collection $items, ?string $parentId): array
    {
        return $items->where('id_departemen_induk', $parentId)->values()->map(function ($item) use ($items) {
            $item->children = $this->buildTree($items, $item->id_departemen);
            return $item;
        })->all();
    }
}
