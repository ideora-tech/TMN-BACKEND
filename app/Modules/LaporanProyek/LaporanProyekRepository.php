<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek;

use App\Modules\LaporanProyek\Contracts\LaporanProyekRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LaporanProyekRepository implements LaporanProyekRepositoryInterface
{
    private const SUBQUERY_TRIP_SELESAI = "(select count(*) from trip t
        join jadwal_keberangkatan jk on t.id_jadwal = jk.id_jadwal
        join penugasan p on jk.id_penugasan = p.id_penugasan
        where p.id_proyek = laporan_proyek.id_proyek
          and t.status = 'selesai'
          and t.dihapus_pada is null and jk.dihapus_pada is null and p.dihapus_pada is null) as total_trip_aktual";

    public function paginate(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return LaporanProyekModel::active()
            ->join('proyek as pr', 'laporan_proyek.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('klien as k', 'k.id_klien', '=', 'pr.id_klien')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('pr.nama_proyek', 'like', "%{$search}%")
                   ->orWhere('pr.kode_proyek', 'like', "%{$search}%");
            }))
            ->select('laporan_proyek.*', 'pr.kode_proyek', 'pr.nama_proyek', 'k.nama_klien')
            ->selectRaw(self::SUBQUERY_TRIP_SELESAI)
            ->orderBy('laporan_proyek.dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function detailById(string $id, string $idPerusahaan): ?LaporanProyekModel
    {
        return LaporanProyekModel::active()
            ->join('proyek as pr', 'laporan_proyek.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('klien as k', 'k.id_klien', '=', 'pr.id_klien')
            ->leftJoin('pengguna as pg', 'pg.id_pengguna', '=', 'laporan_proyek.id_diserahkan_oleh')
            ->where('laporan_proyek.id_laporan', $id)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->select('laporan_proyek.*', 'pr.kode_proyek', 'pr.nama_proyek', 'k.nama_klien', 'pg.username as diserahkan_oleh')
            ->first();
    }

    public function statistikProyek(string $idProyek): array
    {
        $agg = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->leftJoin('laporan_perjalanan as lp', function ($join) {
                $join->on('lp.id_trip', '=', 't.id_trip')->whereNull('lp.dihapus_pada');
            })
            ->where('p.id_proyek', $idProyek)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->selectRaw('count(distinct t.id_trip) as total_trip,
                coalesce(sum(coalesce(lp.jarak_tempuh_km, 0)), 0) as total_jarak,
                coalesce(sum(coalesce(lp.biaya_bbm, 0) + coalesce(lp.uang_tol, 0) + coalesce(lp.uang_jalan, 0)), 0) as total_biaya')
            ->first();

        $biayaLain = (float) DB::table('biaya_lain_trip as bl')
            ->join('laporan_perjalanan as lp', 'bl.id_laporan', '=', 'lp.id_laporan')
            ->join('trip as t', 'lp.id_trip', '=', 't.id_trip')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->where('p.id_proyek', $idProyek)
            ->where('t.status', 'selesai')
            ->whereNull('bl.dihapus_pada')
            ->whereNull('lp.dihapus_pada')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->sum('bl.nominal');

        return [
            'total_trip'     => (int) $agg->total_trip,
            'total_jarak_km' => (float) $agg->total_jarak,
            'total_biaya'    => (float) $agg->total_biaya + $biayaLain,
        ];
    }

    public function semuaUntukExport(string $idPerusahaan): array
    {
        return LaporanProyekModel::active()
            ->join('proyek as pr', 'laporan_proyek.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('klien as k', 'k.id_klien', '=', 'pr.id_klien')
            ->leftJoin('pengguna as pg', 'pg.id_pengguna', '=', 'laporan_proyek.id_diserahkan_oleh')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->select('laporan_proyek.*', 'pr.kode_proyek', 'pr.nama_proyek', 'k.nama_klien', 'pg.username as diserahkan_oleh')
            ->orderBy('laporan_proyek.dibuat_pada', 'desc')
            ->get()
            ->all();
    }

    public function countTripSelesaiByProyek(string $idProyek): int
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->where('p.id_proyek', $idProyek)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->count();
    }

    public function findById(string $id): ?LaporanProyekModel
    {
        return LaporanProyekModel::active()->find($id);
    }

    public function findByProyek(string $idProyek): ?LaporanProyekModel
    {
        return LaporanProyekModel::active()->where('id_proyek', $idProyek)->first();
    }

    public function existsByProyek(string $idProyek): bool
    {
        return LaporanProyekModel::active()->where('id_proyek', $idProyek)->exists();
    }

    public function create(array $data): LaporanProyekModel
    {
        return LaporanProyekModel::create($data);
    }

    public function update(LaporanProyekModel $model, array $data): LaporanProyekModel
    {
        $model->update($data);
        return $model->fresh();
    }
}
