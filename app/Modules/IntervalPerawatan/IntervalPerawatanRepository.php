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
        'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan',
    ];

    private function detailQuery()
    {
        return DB::table('interval_perawatan')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'interval_perawatan.id_jenis_kendaraan')
            ->whereNull('interval_perawatan.dihapus_pada')
            ->select(self::DETAIL_SELECT);
    }

    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $idJenisKendaraan,
        ?string $search = null,
    ): LengthAwarePaginator {
        return $this->detailQuery()
            ->where('interval_perawatan.id_perusahaan', $idPerusahaan)
            ->when($idJenisKendaraan, fn ($q, $v) => $q->where('interval_perawatan.id_jenis_kendaraan', $v))
            ->when($search, fn ($q, $v) => $q->where('jenis_kendaraan.nama_jenis', 'like', "%{$v}%"))
            ->orderBy('jenis_kendaraan.nama_jenis')
            ->orderBy('interval_perawatan.interval_km')
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
        string $idJenisKendaraan,
        ?int $intervalKm,
        ?int $intervalBulan,
        ?string $excludeId = null,
    ): ?object {
        return DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->where(fn ($q) => $intervalKm === null ? $q->whereNull('interval_km') : $q->where('interval_km', $intervalKm))
            ->where(fn ($q) => $intervalBulan === null ? $q->whereNull('interval_bulan') : $q->where('interval_bulan', $intervalBulan))
            ->when($excludeId !== null, fn ($q) => $q->where('id_interval_perawatan', '!=', $excludeId))
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
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->where('aktif', 1)
            ->orderBy('interval_km')
            ->orderBy('interval_bulan')
            ->get(['id_interval_perawatan', 'id_jenis_kendaraan', 'interval_km', 'interval_bulan'])
            ->all();
    }

    public function findAllByJenisKendaraanIds(string $idPerusahaan, array $jenisKendaraanIds): array
    {
        if (empty($jenisKendaraanIds)) {
            return [];
        }

        return DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereIn('id_jenis_kendaraan', $jenisKendaraanIds)
            ->where('aktif', 1)
            ->orderBy('interval_km')
            ->orderBy('interval_bulan')
            ->get(['id_interval_perawatan', 'id_jenis_kendaraan', 'interval_km', 'interval_bulan'])
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

    public function sparepartMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_sparepart', $id)
            ->first();
    }

    public function findSparepartByIntervalIds(array $idIntervalList): array
    {
        if (empty($idIntervalList)) {
            return [];
        }

        return DB::table('interval_perawatan_sparepart as ips')
            ->join('sparepart', 'sparepart.id_sparepart', '=', 'ips.id_sparepart')
            ->whereNull('ips.dihapus_pada')
            ->whereNull('sparepart.dihapus_pada')
            ->whereIn('ips.id_interval_perawatan', $idIntervalList)
            ->orderBy('sparepart.nama')
            ->get([
                'ips.id_interval_perawatan',
                'sparepart.id_sparepart',
                'sparepart.nama as nama_sparepart',
                'sparepart.satuan as satuan_sparepart',
                'ips.qty_standar',
            ])
            ->map(fn ($row) => [
                'id_interval_perawatan' => $row->id_interval_perawatan,
                'id_sparepart'          => $row->id_sparepart,
                'nama_sparepart'        => $row->nama_sparepart,
                'satuan_sparepart'      => $row->satuan_sparepart,
                'qty_standar'           => (int) $row->qty_standar,
            ])
            ->all();
    }

    public function softDeleteSparepartByInterval(string $idIntervalPerawatan): void
    {
        DB::table('interval_perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_interval_perawatan', $idIntervalPerawatan)
            ->update(RecordHelper::stampDelete());
    }

    public function createSparepart(array $data): void
    {
        DB::table('interval_perawatan_sparepart')->insert(RecordHelper::stampCreate($data, 'id_interval_sparepart'));
    }
}
