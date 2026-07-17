<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupirRepository implements SupirRepositoryInterface
{
    private const COLUMNS = [
        'id_supir', 'id_pengguna', 'id_perusahaan', 'id_armada_default', 'nama', 'no_sim',
        'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status', 'foto',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('supir')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama', 'asc')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('supir')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_supir', $id)
            ->first();
    }

    public function findByPengguna(string $idPengguna): ?object
    {
        return DB::table('supir')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_pengguna', $idPengguna)
            ->first();
    }

    public function findByNoSim(string $idPerusahaan, string $noSim): ?object
    {
        return DB::table('supir')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('no_sim', $noSim)
            ->first();
    }

    public function findPemegangArmadaDefault(string $idArmada, ?string $excludeIdSupir = null): ?object
    {
        return DB::table('supir')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_armada_default', $idArmada)
            ->when($excludeIdSupir !== null, fn ($query) => $query->where('id_supir', '!=', $excludeIdSupir))
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_supir');
        DB::table('supir')->insert($data);
        return $this->findById($data['id_supir']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('supir')
            ->where('id_supir', $record->id_supir)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_supir);
    }

    public function delete(object $record): void
    {
        DB::table('supir')
            ->where('id_supir', $record->id_supir)
            ->update(RecordHelper::stampDelete());
    }
}
