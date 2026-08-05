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
        'id_supir', 'id_pengguna', 'id_karyawan', 'id_perusahaan', 'id_armada_default', 'nama', 'no_sim',
        'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status', 'foto',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function findByKaryawan(string $idKaryawan, ?string $excludeIdSupir = null): ?object
    {
        return DB::table('supir')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->when($excludeIdSupir, fn ($q, $v) => $q->where('id_supir', '!=', $v))
            ->first();
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $status = null, ?string $search = null): LengthAwarePaginator
    {
        $query = DB::table('supir')
            ->leftJoin('armada', function ($join) {
                $join->on('armada.id_armada', '=', 'supir.id_armada_default')
                     ->whereNull('armada.dihapus_pada');
            })
            ->leftJoin('pengguna', 'pengguna.id_pengguna', '=', 'supir.id_pengguna')
            ->whereNull('supir.dihapus_pada')
            ->where('supir.id_perusahaan', $idPerusahaan)
            ->orderBy('supir.nama', 'asc');

        if ($status !== null) {
            $query->where('supir.status', $status);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('supir.nama', 'like', "%{$search}%")
                  ->orWhere('supir.no_sim', 'like', "%{$search}%")
                  ->orWhere('armada.nopol', 'like', "%{$search}%");
            });
        }

        return $query->paginate(
            $limit,
            array_merge(
                array_map(fn ($c) => 'supir.' . $c, self::COLUMNS),
                ['armada.nopol as armada_default', 'pengguna.username as username_pengguna'],
            ),
            'page',
            $page
        );
    }

    public function findById(string $id): ?object
    {
        return DB::table('supir')
            ->leftJoin('pengguna', 'pengguna.id_pengguna', '=', 'supir.id_pengguna')
            ->select(array_merge(
                array_map(fn ($c) => 'supir.' . $c, self::COLUMNS),
                ['pengguna.username as username_pengguna'],
            ))
            ->whereNull('supir.dihapus_pada')
            ->where('supir.id_supir', $id)
            ->first();
    }

    public function findByPengguna(string $idPengguna, ?string $excludeIdSupir = null): ?object
    {
        return DB::table('supir')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_pengguna', $idPengguna)
            ->when($excludeIdSupir !== null, fn ($query) => $query->where('id_supir', '!=', $excludeIdSupir))
            ->first();
    }

    public function findPenggunaMilikPerusahaan(string $idPengguna, string $idPerusahaan): ?object
    {
        return DB::table('pengguna')
            ->where('id_pengguna', $idPengguna)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function listOpsiPengguna(string $idPerusahaan): array
    {
        return DB::table('pengguna')
            ->leftJoin('supir', function ($join) {
                $join->on('supir.id_pengguna', '=', 'pengguna.id_pengguna')
                     ->whereNull('supir.dihapus_pada');
            })
            ->where('pengguna.id_perusahaan', $idPerusahaan)
            ->where('pengguna.kode_peran', 'SUPIR')
            ->where('pengguna.aktif', 1)
            ->orderBy('pengguna.username')
            ->get([
                'pengguna.id_pengguna',
                'pengguna.username',
                'pengguna.email',
                'supir.id_supir as id_supir_tertaut',
                'supir.nama as nama_supir_tertaut',
            ])
            ->all();
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
