<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DokumenArmadaRepository implements DokumenArmadaRepositoryInterface
{
    private const COLUMNS = [
        'dokumen_armada.id_dokumen_armada', 'dokumen_armada.id_armada', 'dokumen_armada.jenis_dokumen',
        'dokumen_armada.nomor', 'dokumen_armada.berlaku_sampai', 'dokumen_armada.url_file',
        'dokumen_armada.aktif', 'dokumen_armada.id_dokumen_sebelumnya',
        'dokumen_armada.dibuat_pada', 'dokumen_armada.dibuat_oleh',
        'dokumen_armada.diubah_pada', 'dokumen_armada.diubah_oleh',
        'dokumen_armada.dihapus_pada', 'dokumen_armada.dihapus_oleh',
    ];

    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('dokumen_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->where('aktif', 1)
            ->orderBy('berlaku_sampai')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen, ?string $search = null): LengthAwarePaginator
    {
        return DB::table('dokumen_armada')
            ->join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_armada.dihapus_pada')
            ->whereNull('armada.dihapus_pada')
            ->where('dokumen_armada.aktif', 1)
            ->when($idArmada, fn ($q, $v) => $q->where('dokumen_armada.id_armada', $v))
            ->when($jenisDokumen, fn ($q, $v) => $q->where('dokumen_armada.jenis_dokumen', $v))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('dokumen_armada.jenis_dokumen', 'like', "%{$search}%")
                   ->orWhere('dokumen_armada.nomor', 'like', "%{$search}%")
                   ->orWhere('armada.nopol', 'like', "%{$search}%");
            }))
            ->orderBy('armada.nopol')
            ->orderBy('dokumen_armada.id_armada')
            ->orderBy('dokumen_armada.berlaku_sampai')
            ->select(array_merge(self::COLUMNS, ['armada.nopol as armada_nopol', 'armada.merk as armada_merk']))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function listArmadaPerusahaan(string $idPerusahaan, ?string $idArmada = null, ?string $search = null): array
    {
        return DB::table('armada')
            ->leftJoin('jenis_kendaraan', function ($join) {
                $join->on('jenis_kendaraan.id_jenis_kendaraan', '=', 'armada.id_jenis_kendaraan')
                    ->whereNull('jenis_kendaraan.dihapus_pada');
            })
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('armada.dihapus_pada')
            ->when(
                $idArmada,
                fn ($q, $v) => $q->where('armada.id_armada', $v),
                fn ($q) => $q->where('armada.status', '!=', 'tidak_aktif'),
            )
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('armada.nopol', 'like', "%{$search}%")
                   ->orWhere('armada.merk', 'like', "%{$search}%")
                   ->orWhereExists(function ($sub) use ($search) {
                       $sub->from('dokumen_armada')
                           ->whereColumn('dokumen_armada.id_armada', 'armada.id_armada')
                           ->whereNull('dokumen_armada.dihapus_pada')
                           ->where('dokumen_armada.aktif', 1)
                           ->where(fn ($s) => $s->where('dokumen_armada.nomor', 'like', "%{$search}%")
                               ->orWhere('dokumen_armada.jenis_dokumen', 'like', "%{$search}%"));
                   });
            }))
            ->orderBy('armada.nopol')
            ->select(
                'armada.id_armada',
                'armada.nopol',
                'armada.merk',
                'armada.status as status_armada',
                'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan',
            )
            ->get()
            ->all();
    }

    public function listAktifByArmadaIds(array $idArmada, ?string $jenisDokumen = null): array
    {
        if (empty($idArmada)) {
            return [];
        }

        return DB::table('dokumen_armada')
            ->join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->whereIn('dokumen_armada.id_armada', $idArmada)
            ->whereNull('dokumen_armada.dihapus_pada')
            ->where('dokumen_armada.aktif', 1)
            ->when($jenisDokumen, fn ($q, $v) => $q->where('dokumen_armada.jenis_dokumen', $v))
            ->orderByRaw('dokumen_armada.berlaku_sampai IS NULL')
            ->orderBy('dokumen_armada.berlaku_sampai')
            ->orderBy('dokumen_armada.jenis_dokumen')
            ->select(array_merge(self::COLUMNS, ['armada.nopol as armada_nopol', 'armada.merk as armada_merk']))
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        return DB::table('dokumen_armada')
            ->leftJoin('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->whereNull('dokumen_armada.dihapus_pada')
            ->where('dokumen_armada.id_dokumen_armada', $id)
            ->select(array_merge(self::COLUMNS, [
                'armada.nopol as armada_nopol',
                'armada.merk as armada_merk',
                'armada.id_perusahaan as armada_id_perusahaan',
            ]))
            ->first();
    }

    public function listAktifByArmada(string $idArmada): array
    {
        return DB::table('dokumen_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->where('aktif', 1)
            ->orderBy('jenis_dokumen')
            ->orderBy('berlaku_sampai')
            ->select(self::COLUMNS)
            ->get()
            ->all();
    }

    public function findPengganti(string $id): ?object
    {
        $idPengganti = DB::table('dokumen_armada')
            ->whereNull('dihapus_pada')
            ->where('id_dokumen_sebelumnya', $id)
            ->orderByDesc('dibuat_pada')
            ->value('id_dokumen_armada');

        return $idPengganti !== null ? $this->findById((string) $idPengganti) : null;
    }

    public function adaAktif(string $idArmada, string $jenisDokumen, ?string $kecualiId = null): bool
    {
        return DB::table('dokumen_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->where('jenis_dokumen', $jenisDokumen)
            ->where('aktif', 1)
            ->when($kecualiId, fn ($q, $v) => $q->where('id_dokumen_armada', '<>', $v))
            ->exists();
    }

    public function hitungSegeraHabis(string $idPerusahaan, string $dari, string $sampai): int
    {
        return DB::table('dokumen_armada')
            ->join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('armada.dihapus_pada')
            ->whereNull('dokumen_armada.dihapus_pada')
            ->where('dokumen_armada.aktif', 1)
            ->whereBetween('dokumen_armada.berlaku_sampai', [$dari, $sampai])
            ->count();
    }

    public function findExpiring(string $idPerusahaan, int $days): array
    {
        return DB::table('dokumen_armada')
            ->join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_armada.dihapus_pada')
            ->where('dokumen_armada.aktif', 1)
            ->whereNotNull('dokumen_armada.berlaku_sampai')
            ->where('dokumen_armada.berlaku_sampai', '<=', now()->addDays($days))
            ->select(self::COLUMNS)
            ->get()
            ->all();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_dokumen_armada');
        DB::table('dokumen_armada')->insert($data);
        return $this->findById($data['id_dokumen_armada']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('dokumen_armada')
            ->where('id_dokumen_armada', $record->id_dokumen_armada)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_dokumen_armada);
    }

    public function delete(object $record): void
    {
        DB::table('dokumen_armada')
            ->where('id_dokumen_armada', $record->id_dokumen_armada)
            ->update(RecordHelper::stampDelete());
    }
}
