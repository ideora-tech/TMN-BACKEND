<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan;

use App\Modules\IntervalPerawatan\Contracts\IntervalPerawatanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class IntervalPerawatanRepository implements IntervalPerawatanRepositoryInterface
{
    private const DETAIL_SELECT = [
        'interval_perawatan.*',
        'jenis_perawatan.nama as nama_jenis_perawatan',
        'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan',
    ];

    private function detailQuery()
    {
        return DB::table('interval_perawatan')
            ->leftJoin('jenis_perawatan', 'jenis_perawatan.id_jenis_perawatan', '=', 'interval_perawatan.id_jenis_perawatan')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'interval_perawatan.id_jenis_kendaraan')
            ->whereNull('interval_perawatan.dihapus_pada')
            ->select(self::DETAIL_SELECT);
    }

    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $idJenisPerawatan,
        ?string $idJenisKendaraan,
    ): LengthAwarePaginator {
        return $this->detailQuery()
            ->where('interval_perawatan.id_perusahaan', $idPerusahaan)
            ->when($idJenisPerawatan, fn ($q, $v) => $q->where('interval_perawatan.id_jenis_perawatan', $v))
            ->when($idJenisKendaraan, fn ($q, $v) => $q->where('interval_perawatan.id_jenis_kendaraan', $v))
            ->orderBy('jenis_perawatan.nama')
            ->orderBy('jenis_kendaraan.nama_jenis')
            ->paginate($limit, self::DETAIL_SELECT, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_interval_perawatan', $id)
            ->first();
    }

    public function findDetailById(string $id): ?object
    {
        return $this->detailQuery()->where('interval_perawatan.id_interval_perawatan', $id)->first();
    }

    public function findByKombinasi(
        string $idPerusahaan,
        string $idJenisPerawatan,
        string $idJenisKendaraan,
        ?string $excludeId = null,
    ): ?object {
        return DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_perawatan', $idJenisPerawatan)
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->when($excludeId !== null, fn ($q) => $q->where('id_interval_perawatan', '!=', $excludeId))
            ->first();
    }

    public function jenisPerawatanMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jenis_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_perawatan', $id)
            ->first();
    }

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_kendaraan', $id)
            ->first();
    }

    public function findAllByJenisKendaraan(string $idPerusahaan, string $idJenisKendaraan): array
    {
        return DB::table('interval_perawatan')
            ->join('jenis_perawatan', 'jenis_perawatan.id_jenis_perawatan', '=', 'interval_perawatan.id_jenis_perawatan')
            ->whereNull('interval_perawatan.dihapus_pada')
            ->whereNull('jenis_perawatan.dihapus_pada')
            ->where('interval_perawatan.id_perusahaan', $idPerusahaan)
            ->where('interval_perawatan.id_jenis_kendaraan', $idJenisKendaraan)
            ->where('interval_perawatan.aktif', 1)
            ->where('jenis_perawatan.aktif', 1)
            ->orderBy('jenis_perawatan.nama')
            ->get([
                'interval_perawatan.id_jenis_perawatan',
                'jenis_perawatan.nama as nama_jenis_perawatan',
                'interval_perawatan.interval_hari',
            ])
            ->all();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_interval_perawatan');
        DB::table('interval_perawatan')->insert($data);
        return $this->findById($data['id_interval_perawatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('interval_perawatan')
            ->where('id_interval_perawatan', $record->id_interval_perawatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_interval_perawatan);
    }

    public function delete(object $record): void
    {
        DB::table('interval_perawatan')
            ->where('id_interval_perawatan', $record->id_interval_perawatan)
            ->update(RecordHelper::stampDelete());
    }
}
