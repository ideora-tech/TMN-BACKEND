<?php
declare(strict_types=1);

namespace App\Modules\Supplier;

use App\Modules\Supplier\Contracts\SupplierRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplierRepository implements SupplierRepositoryInterface
{
    private const COLUMNS = [
        'id_supplier', 'id_perusahaan', 'nama', 'telepon', 'alamat', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?int $aktif = null): LengthAwarePaginator
    {
        return DB::table('supplier')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where('nama', 'like', "%{$search}%"))
            ->when($aktif !== null, fn ($q) => $q->where('aktif', $aktif))
            ->orderBy('nama')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('supplier')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_supplier', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_supplier');
        DB::table('supplier')->insert($data);
        return $this->findById($data['id_supplier']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('supplier')->where('id_supplier', $record->id_supplier)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_supplier);
    }

    public function delete(object $record): void
    {
        DB::table('supplier')->where('id_supplier', $record->id_supplier)
            ->update(RecordHelper::stampDelete());
    }
}
