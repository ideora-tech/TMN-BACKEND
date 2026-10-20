<?php

declare(strict_types=1);

namespace App\Modules\PerusahaanGrup;

use App\Modules\PerusahaanGrup\Contracts\PerusahaanGrupRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PerusahaanGrupRepository implements PerusahaanGrupRepositoryInterface
{
    private const COLUMNS = [
        'id_perusahaan_grup', 'id_perusahaan', 'singkatan', 'nama_lengkap', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator
    {
        $query = DB::table('perusahaan_grup')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('singkatan', 'like', "%{$search}%")
                  ->orWhere('nama_lengkap', 'like', "%{$search}%");
            });
        }

        if ($aktif !== null) {
            $query->where('aktif', $aktif ? 1 : 0);
        }

        return $query->orderBy('singkatan')->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function listAktifByPerusahaan(string $idPerusahaan): array
    {
        return DB::table('perusahaan_grup')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->orderBy('singkatan')
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        return DB::table('perusahaan_grup')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan_grup', $id)
            ->first();
    }

    public function findBySingkatan(string $idPerusahaan, string $singkatan, ?string $kecualiId = null): ?object
    {
        return DB::table('perusahaan_grup')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereRaw('LOWER(TRIM(singkatan)) = ?', [mb_strtolower(trim($singkatan))])
            ->when($kecualiId !== null, fn ($q) => $q->where('id_perusahaan_grup', '!=', $kecualiId))
            ->first();
    }

    public function findBySingkatanAtauNama(string $idPerusahaan, string $teks): ?object
    {
        $kunci = mb_strtolower(trim($teks));

        return $this->findBySingkatan($idPerusahaan, $kunci)
            ?? DB::table('perusahaan_grup')
                ->select(self::COLUMNS)
                ->whereNull('dihapus_pada')
                ->where('id_perusahaan', $idPerusahaan)
                ->whereRaw('LOWER(TRIM(nama_lengkap)) = ?', [$kunci])
                ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_perusahaan_grup');
        DB::table('perusahaan_grup')->insert($data);
        return $this->findById($data['id_perusahaan_grup']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('perusahaan_grup')
            ->where('id_perusahaan_grup', $record->id_perusahaan_grup)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_perusahaan_grup);
    }

    public function delete(object $record): void
    {
        DB::table('perusahaan_grup')
            ->where('id_perusahaan_grup', $record->id_perusahaan_grup)
            ->update(RecordHelper::stampDelete());
    }

    public function dipakaiArmada(string $id): bool
    {
        return DB::table('armada')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan_grup', $id)
            ->exists();
    }
}
