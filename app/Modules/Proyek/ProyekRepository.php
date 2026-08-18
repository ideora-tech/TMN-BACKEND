<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProyekRepository implements ProyekRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        return ProyekModel::active()
            ->leftJoin('klien as k', 'k.id_klien', '=', 'proyek.id_klien')
            ->where('proyek.id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('proyek.nama_proyek', 'like', "%{$search}%")
                   ->orWhere('proyek.kode_proyek', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q, $v) => $q->where('proyek.status', $v))
            ->orderBy('proyek.dibuat_pada', 'desc')
            ->select('proyek.*', 'k.nama_klien')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByKlien(string $idKlien, string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        return ProyekModel::active()
            ->where('id_klien', $idKlien)
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nama_proyek', 'like', "%{$search}%")
                   ->orWhere('kode_proyek', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?ProyekModel
    {
        return ProyekModel::active()->find($id);
    }

    public function findByKode(string $idPerusahaan, string $kode): ?ProyekModel
    {
        return ProyekModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_proyek', $kode)
            ->first();
    }

    public function create(array $data): ProyekModel
    {
        return ProyekModel::create($data);
    }

    public function update(ProyekModel $model, array $data): ProyekModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ProyekModel $model): void
    {
        $model->softDelete();
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    public function findByIdForUpdate(string $id): ?object
    {
        return DB::table('proyek')
            ->where('id_proyek', $id)
            ->whereNull('dihapus_pada')
            ->lockForUpdate()
            ->first();
    }

    public function totalFakturProyek(string $idProyek): float
    {
        return (float) DB::table('faktur')
            ->where('id_proyek', $idProyek)
            ->where('status', '!=', 'batal')
            ->whereNull('dihapus_pada')
            ->sum('total');
    }

    public function tripSelesaiUntukRealisasi(string $idProyek): array
    {
        $rows = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->leftJoin('armada as a', 'p.id_armada', '=', 'a.id_armada')
            ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
            ->leftJoin('laporan_perjalanan as lp', 'lp.id_trip', '=', 't.id_trip')
            ->where('p.id_proyek', $idProyek)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('lp.dihapus_pada')
            ->select([
                't.id_trip',
                DB::raw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) as tanggal'),
                'jk.id_rute',
                'p.sumber',
                'p.id_supir',
                'p.id_armada',
                'a.id_jenis_kendaraan',
                'av.id_jenis_kendaraan as id_jenis_kendaraan_vendor',
                'lp.id_laporan',
            ])
            ->get();

        $this->isiArmadaAlokasi($rows);

        return $rows->all();
    }

    /**
     * Trip supir shift: penugasan tanpa id_armada — armada hariannya dari
     * alokasi_armada (konsisten PenagihanTripRepository::isiArmadaAlokasi &
     * KonsolidasiKlienRepository::isiArmadaAlokasi).
     * Trip sumber vendor dikecualikan: unit_only juga ber-id_armada null padahal
     * armadanya armada vendor — jenis kendaraan harus dari armada_vendor, bukan alokasi.
     */
    private function isiArmadaAlokasi(\Illuminate\Support\Collection $rows): void
    {
        $butuh = $rows->filter(fn ($r) => $r->id_armada === null && $r->id_supir !== null && ($r->sumber ?? 'internal') !== 'vendor');
        if ($butuh->isEmpty()) {
            return;
        }

        $alokasi = DB::table('alokasi_armada as aa')
            ->join('armada as arm', 'arm.id_armada', '=', 'aa.id_armada')
            ->whereNull('aa.dihapus_pada')
            ->whereIn('aa.id_supir', $butuh->pluck('id_supir')->unique()->values())
            ->select('aa.id_supir', 'aa.tanggal', 'arm.id_jenis_kendaraan')
            ->get()
            ->keyBy(fn ($row) => $row->id_supir . '|' . substr((string) $row->tanggal, 0, 10));

        foreach ($butuh as $row) {
            $cocok = $alokasi->get($row->id_supir . '|' . substr((string) $row->tanggal, 0, 10));
            if ($cocok !== null) {
                $row->id_jenis_kendaraan = $row->id_jenis_kendaraan ?? $cocok->id_jenis_kendaraan;
            }
        }
    }

    public function totalBiayaTagihanUntukLaporan(array $idLaporans): float
    {
        if ($idLaporans === []) {
            return 0.0;
        }

        return (float) DB::table('biaya_tagihan_trip')
            ->whereIn('id_laporan', $idLaporans)
            ->whereNull('dihapus_pada')
            ->sum('nominal');
    }
}
