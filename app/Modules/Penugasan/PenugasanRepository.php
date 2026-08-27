<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PenugasanRepository implements PenugasanRepositoryInterface
{
    public function listJadwalSupirVendor(string $idSupirVendor, string $dari, string $sampai): Collection
    {
        $records = PenugasanModel::active()
            ->where('id_supir_vendor', $idSupirVendor)
            ->whereBetween('tanggal_tugas', [$dari, $sampai])
            ->orderBy('tanggal_tugas')
            ->orderBy('dibuat_pada')
            ->get();

        $this->attachProyekArmada($records);

        return $records;
    }

    public function listJadwalSupir(string $idSupir, string $dari, string $sampai): Collection
    {
        $records = PenugasanModel::active()
            ->where('id_supir', $idSupir)
            ->whereBetween('tanggal_tugas', [$dari, $sampai])
            ->orderBy('tanggal_tugas')
            ->orderBy('dibuat_pada')
            ->get();

        $this->attachProyekArmada($records);

        return $records;
    }

    public function paginateByProyek(string $idProyek, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->where('id_proyek', $idProyek)
            ->when($sumber, fn ($q, $v) => $this->terapkanFilterSumber($q, $v))
            ->when($status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /** Tabel penugasan tidak punya id_perusahaan — tenant di-scope lewat proyek. */
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator
    {
        $paginator = PenugasanModel::active()
            ->join('proyek', function ($join) use ($idPerusahaan) {
                $join->on('proyek.id_proyek', '=', 'penugasan.id_proyek')
                    ->where('proyek.id_perusahaan', $idPerusahaan)
                    ->whereNull('proyek.dihapus_pada');
            })
            ->select('penugasan.*')
            ->when($sumber, fn ($q, $v) => $this->terapkanFilterSumber($q, $v))
            ->when($status, fn ($q, $v) => $q->whereIn('penugasan.status', explode(',', $v)))
            ->orderBy('penugasan.tanggal_tugas', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachProyekArmada($paginator->getCollection());

        return $paginator;
    }

    public function paginateByArmada(string $idArmada, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->where('id_armada', $idArmada)
            ->when($sumber, fn ($q, $v) => $this->terapkanFilterSumber($q, $v))
            ->when($status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->orderBy('tanggal_tugas', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateBySupir(string $idSupir, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator
    {
        $paginator = PenugasanModel::active()
            ->where('id_supir', $idSupir)
            ->when($sumber, fn ($q, $v) => $this->terapkanFilterSumber($q, $v))
            ->when($status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->orderBy('tanggal_tugas', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachProyekArmada($paginator->getCollection());

        return $paginator;
    }

    /**
     * Lampirkan pseudo-relasi proyek/armada via query builder biasa (bukan
     * Eloquent with()/whereHas()) — dipakai konsumen (TripService dkk) yang
     * masih mengakses ->proyek->nama_proyek / ->armada->nopol seperti relasi
     * asli, jadi cukup di-setRelation() tanpa mengubah kode pemanggil.
     */
    /**
     * Stempel aktivitas terakhir penugasan & trip milik perusahaan — dipakai web
     * untuk mendeteksi perubahan dari mobile tanpa menarik ulang seluruh board.
     */
    public function stempelAktivitasBoard(string $idPerusahaan): ?string
    {
        $stempel = [
            DB::table('penugasan')
                ->join('proyek', 'proyek.id_proyek', '=', 'penugasan.id_proyek')
                ->where('proyek.id_perusahaan', $idPerusahaan)
                ->max('penugasan.dibuat_pada'),
            DB::table('penugasan')
                ->join('proyek', 'proyek.id_proyek', '=', 'penugasan.id_proyek')
                ->where('proyek.id_perusahaan', $idPerusahaan)
                ->max('penugasan.diubah_pada'),
            DB::table('penugasan')
                ->join('proyek', 'proyek.id_proyek', '=', 'penugasan.id_proyek')
                ->where('proyek.id_perusahaan', $idPerusahaan)
                ->max('penugasan.dihapus_pada'),
            DB::table('trip')
                ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
                ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
                ->join('proyek', 'proyek.id_proyek', '=', 'p.id_proyek')
                ->where('proyek.id_perusahaan', $idPerusahaan)
                ->max('trip.dibuat_pada'),
            DB::table('trip')
                ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
                ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
                ->join('proyek', 'proyek.id_proyek', '=', 'p.id_proyek')
                ->where('proyek.id_perusahaan', $idPerusahaan)
                ->max('trip.diubah_pada'),
        ];

        $terisi = array_filter(array_map(static fn ($s) => $s !== null ? (string) $s : null, $stempel));

        return $terisi === [] ? null : max($terisi);
    }

    private function attachProyekArmada(Collection $records): void
    {
        $idProyekList = $records->pluck('id_proyek')->unique()->filter()->values();
        $idArmadaList = $records->pluck('id_armada')->unique()->filter()->values();
        $idArmadaVendorList = $records->pluck('id_armada_vendor')->unique()->filter()->values();

        $proyekMap = $idProyekList->isEmpty() ? collect()
            : DB::table('proyek')->whereIn('id_proyek', $idProyekList)
                ->get(['id_proyek', 'kode_proyek', 'nama_proyek'])->keyBy('id_proyek');
        $armadaMap = $idArmadaList->isEmpty() ? collect()
            : DB::table('armada')->whereIn('id_armada', $idArmadaList)
                ->get(['id_armada', 'nopol'])->keyBy('id_armada');
        $armadaVendorMap = $idArmadaVendorList->isEmpty() ? collect()
            : DB::table('armada_vendor')->whereIn('id_armada_vendor', $idArmadaVendorList)
                ->get(['id_armada_vendor', 'nopol'])->keyBy('id_armada_vendor');

        foreach ($records as $record) {
            $record->setRelation('proyek', $proyekMap->get($record->id_proyek));
            $record->setRelation('armada', $armadaMap->get($record->id_armada));
            $record->setRelation('armadaVendor', $armadaVendorMap->get($record->id_armada_vendor));
        }
    }

    /**
     * `sumber=operasional` adalah filter gabungan khusus untuk daftar Penugasan
     * Operasional: internal + vendor bermekanisme unit_only (id_supir_vendor kosong
     * membedakannya dari unit_driver/full yang tetap hanya tampil di Penugasan Vendor).
     * Nilai lain ('internal'/'vendor') tetap exact-match seperti sebelumnya.
     */
    private function terapkanFilterSumber($query, string $sumber): void
    {
        if ($sumber === 'operasional') {
            $query->where(function ($q) {
                $q->where('sumber', 'internal')
                    ->orWhere(function ($q2) {
                        $q2->where('sumber', 'vendor')->whereNull('id_supir_vendor');
                    });
            });
            return;
        }

        $query->where('sumber', $sumber);
    }

    public function countSelesaiByProyek(string $idProyek): int
    {
        return PenugasanModel::active()
            ->where('id_proyek', $idProyek)
            ->where('status', 'selesai')
            ->count();
    }

    public function findById(string $id): ?PenugasanModel
    {
        return PenugasanModel::active()->find($id);
    }

    public function hasConflict(string $idKaryawan, string $tanggalTugas, ?string $excludeId = null): bool
    {
        $query = PenugasanModel::active()
            ->where('id_karyawan', $idKaryawan)
            ->where('tanggal_tugas', $tanggalTugas)
            ->whereIn('status', ['pending', 'aktif']);

        if ($excludeId !== null) {
            $query->where('id_penugasan', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function adaKonflikAktorPadaTanggal(string $kolomAktor, string $idAktor, string $tanggalTugas, ?string $excludeId = null): bool
    {
        if (!in_array($kolomAktor, ['id_armada_vendor', 'id_supir_vendor', 'id_supir'], true)) {
            return false;
        }

        $query = PenugasanModel::active()
            ->where($kolomAktor, $idAktor)
            ->where('tanggal_tugas', $tanggalTugas)
            ->whereIn('status', ['pending', 'aktif']);

        if ($excludeId !== null) {
            $query->where('id_penugasan', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function adaPenugasanSupirPadaTanggal(string $idSupir, string $tanggal, string $idProyek, ?string $idRute, ?string $excludeId = null): bool
    {
        $query = PenugasanModel::active()
            ->where('id_supir', $idSupir)
            ->where('tanggal_tugas', $tanggal)
            ->where('id_proyek', $idProyek)
            ->where('status', '!=', 'batal');

        if ($idRute !== null) {
            $query->where('id_rute', $idRute);
        }

        if ($excludeId !== null) {
            $query->where('id_penugasan', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function create(array $data): PenugasanModel
    {
        return PenugasanModel::create($data);
    }

    public function update(PenugasanModel $model, array $data): PenugasanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PenugasanModel $model): void
    {
        $model->softDelete();
    }

    public function syncTitikDrop(string $idPenugasan, array $lokasiList): void
    {
        DB::table('titik_drop_penugasan')
            ->where('id_penugasan', $idPenugasan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        foreach (array_values($lokasiList) as $i => $lokasi) {
            DB::table('titik_drop_penugasan')->insert(RecordHelper::stampCreate([
                'id_penugasan' => $idPenugasan,
                'urutan'       => $i + 1,
                'lokasi'       => trim((string) $lokasi),
            ], 'id_titik_drop'));
        }
    }

    public function titikDropUntukBanyak(array $idPenugasan): array
    {
        if ($idPenugasan === []) {
            return [];
        }

        return DB::table('titik_drop_penugasan')
            ->whereIn('id_penugasan', $idPenugasan)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_penugasan', 'lokasi'])
            ->groupBy('id_penugasan')
            ->map(fn ($g) => $g->pluck('lokasi')->all())
            ->all();
    }

    /**
     * Reverse lookup id_armada_default hanya boleh ketemu satu supir aktif
     * per armada (aturan "1 armada = 1 supir pegangan" dijaga di
     * SupirService::assertArmadaDefaultRules) — leftJoin di sini aman tanpa
     * risiko armada tampil dobel.
     */
    public function boardUnits(string $idPerusahaan): array
    {
        return DB::table('armada')
            ->leftJoin('jenis_kendaraan', function ($join) {
                $join->on('jenis_kendaraan.id_jenis_kendaraan', '=', 'armada.id_jenis_kendaraan')
                    ->whereNull('jenis_kendaraan.dihapus_pada');
            })
            ->leftJoin('supir', function ($join) {
                $join->on('supir.id_armada_default', '=', 'armada.id_armada')
                    ->where('supir.status', 'aktif')
                    ->whereNull('supir.dihapus_pada');
            })
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->where('armada.aktif', 1)
            ->whereNull('armada.dihapus_pada')
            ->orderBy('armada.nopol')
            ->get([
                'armada.id_armada',
                'armada.nopol',
                'jenis_kendaraan.nama_jenis',
                'supir.id_supir as id_supir_default',
                'supir.nama as nama_supir_default',
            ])
            ->map(fn ($row) => [
                'tipe'               => 'internal',
                'id_armada'          => $row->id_armada,
                'nopol'              => $row->nopol,
                'nama_jenis'         => $row->nama_jenis,
                'id_supir_default'   => $row->id_supir_default,
                'nama_supir_default' => $row->nama_supir_default,
            ])
            ->all();
    }

    /**
     * Tabel penugasan tidak punya id_perusahaan — tenant di-scope lewat proyek,
     * sama seperti paginateByPerusahaan. rute/supir/supir_vendor sengaja
     * leftJoin dengan filter dihapus_pada DI KONDISI JOIN (bukan whereNull di
     * level query) — supaya baris penugasan tetap tampil di board walau master
     * yang direferensikan sudah soft-deleted (nama_rute/nama_supir jadi null),
     * bukan malah membuat seluruh baris assignment hilang.
     */
    public function boardAssignments(string $idPerusahaan, string $dari, string $sampai): array
    {
        return DB::table('penugasan as p')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->leftJoin('rute as r', function ($join) {
                $join->on('r.id_rute', '=', 'p.id_rute')
                    ->whereNull('r.dihapus_pada');
            })
            ->leftJoin('supir as s', function ($join) {
                $join->on('s.id_supir', '=', 'p.id_supir')
                    ->whereNull('s.dihapus_pada');
            })
            ->leftJoin('supir_vendor as sv', function ($join) {
                $join->on('sv.id_supir_vendor', '=', 'p.id_supir_vendor')
                    ->whereNull('sv.dihapus_pada');
            })
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereBetween('p.tanggal_tugas', [$dari, $sampai])
            ->orderBy('p.tanggal_tugas')
            ->select(
                'p.id_penugasan',
                'p.tanggal_tugas as tanggal',
                'p.id_armada',
                'p.id_armada_vendor',
                'p.id_supir',
                'p.id_supir_vendor',
                DB::raw('COALESCE(s.nama, sv.nama) as nama_supir'),
                'p.id_proyek',
                'pr.kode_proyek',
                'pr.nama_proyek',
                'p.id_rute',
                'r.nama_rute',
                'p.estimasi_biaya',
                'p.id_pengajuan',
                'p.status',
            )
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Pola sama dengan TripRepository::statusTripPerSupirTanggal, tapi
     * dikelompokkan per id_penugasan (bukan per supir+tanggal) karena Board
     * Unit sudah punya satu baris assignment per tanggal. 'belum_mulai'
     * dianggap 'berjalan' (armada sudah dikunci) sesuai pola yang sama.
     */
    public function tripsUntukPenugasanList(array $idPenugasanList): array
    {
        if ($idPenugasanList === []) {
            return [];
        }

        $rows = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->whereIn('jk.id_penugasan', $idPenugasanList)
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereIn('t.status', ['belum_mulai', 'berjalan', 'selesai'])
            ->orderBy('t.dibuat_pada')
            ->get(['jk.id_penugasan', 't.id_trip', 't.status']);

        $map = [];
        foreach ($rows as $row) {
            $status = $row->status === 'belum_mulai' ? 'berjalan' : $row->status;
            $map[$row->id_penugasan][] = ['id_trip' => $row->id_trip, 'status' => $status];
        }

        return $map;
    }
}
