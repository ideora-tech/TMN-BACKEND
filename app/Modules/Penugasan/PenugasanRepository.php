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

    public function riwayatArmadaSupir(string $idSupir): array
    {
        return DB::table('penugasan as p')
            ->leftJoin('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoin('armada_vendor as av', 'av.id_armada_vendor', '=', 'p.id_armada_vendor')
            ->where('p.id_supir', $idSupir)
            ->whereNull('p.dihapus_pada')
            ->orderByDesc('p.tanggal_tugas')
            ->orderByDesc('p.dibuat_pada')
            ->select(
                'p.id_penugasan',
                'p.tanggal_tugas',
                'p.status',
                'p.sumber',
                'pr.kode_proyek',
                'pr.nama_proyek',
                DB::raw('COALESCE(a.nopol, av.nopol) as nopol'),
                'a.merk',
                'a.model',
            )
            ->get()
            ->all();
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

    /** @return array<string, array{id: string, label: string}> kunci = nopol UPPERCASE */
    public function petaArmadaAktifByNopol(string $idPerusahaan): array
    {
        $peta = [];
        $rows = DB::table('armada')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('status', '!=', 'tidak_aktif')
            ->get(['id_armada', 'nopol']);
        foreach ($rows as $row) {
            $peta[mb_strtoupper(trim((string) $row->nopol))] = ['id' => (string) $row->id_armada, 'label' => (string) $row->nopol];
        }
        return $peta;
    }

    /** @return array<string, array{id: string, label: string, ganda: bool}> kunci = nama UPPERCASE */
    public function petaSupirAktifByNama(string $idPerusahaan): array
    {
        $peta = [];
        $rows = DB::table('supir')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('status', 'aktif')
            ->get(['id_supir', 'nama']);
        foreach ($rows as $row) {
            $kunci = mb_strtoupper(trim((string) $row->nama));
            if (isset($peta[$kunci])) {
                $peta[$kunci]['ganda'] = true;
                continue;
            }
            $peta[$kunci] = ['id' => (string) $row->id_supir, 'label' => (string) $row->nama, 'ganda' => false];
        }
        return $peta;
    }

    /** @return array<int, array{id: string, kode: string, nama: string}> hanya rute yang terdaftar di rate card proyek */
    public function ruteProyekTerdaftar(string $idProyek, string $idPerusahaan): array
    {
        return DB::table('proyek_rute')
            ->join('rute', function ($join) {
                $join->on('rute.id_rute', '=', 'proyek_rute.id_rute')
                    ->whereNull('rute.dihapus_pada');
            })
            ->join('proyek', function ($join) use ($idPerusahaan) {
                $join->on('proyek.id_proyek', '=', 'proyek_rute.id_proyek')
                    ->where('proyek.id_perusahaan', $idPerusahaan)
                    ->whereNull('proyek.dihapus_pada');
            })
            ->whereNull('proyek_rute.dihapus_pada')
            ->where('proyek_rute.id_proyek', $idProyek)
            ->get(['rute.id_rute', 'rute.kode_rute', 'rute.nama_rute'])
            ->map(fn ($row) => [
                'id'   => (string) $row->id_rute,
                'kode' => (string) $row->kode_rute,
                'nama' => (string) $row->nama_rute,
            ])
            ->unique('id')
            ->values()
            ->all();
    }

    public function milikPerusahaan(string $idPenugasan, string $idPerusahaan): bool
    {
        return PenugasanModel::active()
            ->join('proyek', function ($join) use ($idPerusahaan) {
                $join->on('proyek.id_proyek', '=', 'penugasan.id_proyek')
                    ->where('proyek.id_perusahaan', $idPerusahaan)
                    ->whereNull('proyek.dihapus_pada');
            })
            ->where('penugasan.id_penugasan', $idPenugasan)
            ->exists();
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
        // Hanya penugasan kembar yang BELUM BERANGKAT (tanpa trip hidup) yang
        // dianggap duplikat input. Begitu tripnya jalan/selesai, penugasan baru
        // pada proyek+rute+tanggal sama adalah antrean rit berikutnya — sah;
        // larangan dobel eksekusi tetap dijaga di level mulai trip.
        $query = PenugasanModel::active()
            ->where('id_supir', $idSupir)
            ->where('tanggal_tugas', $tanggal)
            ->where('id_proyek', $idProyek)
            ->whereNotIn('status', ['batal', 'selesai'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('trip as t')
                    ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
                    ->whereColumn('jk.id_penugasan', 'penugasan.id_penugasan')
                    ->whereNull('t.dihapus_pada')
                    ->whereNull('jk.dihapus_pada')
                    ->where('t.status', '!=', 'dibatalkan');
            });

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

    public function adaPenugasanSamaPadaTanggal(string $idProyek, ?string $idRute, string $tanggal, ?string $idArmada, ?string $idArmadaVendor, ?string $idSupir): bool
    {
        return PenugasanModel::active()
            ->where('id_proyek', $idProyek)
            ->where('tanggal_tugas', $tanggal)
            ->whereNotIn('status', ['batal', 'selesai'])
            ->when($idRute !== null, fn ($q) => $q->where('id_rute', $idRute))
            ->when($idArmada !== null, fn ($q) => $q->where('id_armada', $idArmada), fn ($q) => $q->where('id_armada_vendor', $idArmadaVendor))
            ->where('id_supir', $idSupir)
            ->exists();
    }

    public function listUntukSinkronProyek(string $idProyek): array
    {
        return DB::table('penugasan as p')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoin('armada_vendor as av', 'av.id_armada_vendor', '=', 'p.id_armada_vendor')
            ->leftJoin('supir as s', 's.id_supir', '=', 'p.id_supir')
            ->leftJoin('supir_vendor as sv', 'sv.id_supir_vendor', '=', 'p.id_supir_vendor')
            ->where('p.id_proyek', $idProyek)
            ->whereNull('p.dihapus_pada')
            ->where('p.status', '!=', 'batal')
            ->orderBy('p.tanggal_tugas')
            ->select(
                'p.id_penugasan',
                'p.tanggal_tugas',
                'p.status',
                'p.id_armada',
                'p.id_armada_vendor',
                'p.id_supir',
                'p.id_supir_vendor',
                'p.id_rute',
                'p.keterangan',
                DB::raw('COALESCE(a.nopol, av.nopol) as nopol'),
                DB::raw('COALESCE(s.nama, sv.nama) as nama_supir'),
            )
            ->get()
            ->all();
    }

    public function syncTitikDrop(string $idPenugasan, array $items): void
    {
        DB::table('titik_drop_penugasan')
            ->where('id_penugasan', $idPenugasan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        foreach (array_values($items) as $i => $item) {
            [$lokasi, $uangJalanTambahan] = $this->pecahItemTitikDrop($item);
            DB::table('titik_drop_penugasan')->insert(RecordHelper::stampCreate([
                'id_penugasan'         => $idPenugasan,
                'urutan'               => $i + 1,
                'lokasi'               => $lokasi,
                'uang_jalan_tambahan'  => $uangJalanTambahan,
            ], 'id_titik_drop'));
        }
    }

    /**
     * Item titik_drop diterima dalam 2 bentuk: string polos (caller lama)
     * atau objek {lokasi, uang_jalan_tambahan} (dialog Penugasan Harian).
     *
     * @return array{0: string, 1: float}
     */
    private function pecahItemTitikDrop(mixed $item): array
    {
        if (is_array($item)) {
            return [trim((string) ($item['lokasi'] ?? '')), (float) ($item['uang_jalan_tambahan'] ?? 0)];
        }
        return [trim((string) $item), 0.0];
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

    public function titikDropDetailUntukBanyak(array $idPenugasanList): array
    {
        if ($idPenugasanList === []) {
            return [];
        }

        return DB::table('titik_drop_penugasan')
            ->whereIn('id_penugasan', $idPenugasanList)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_penugasan', 'lokasi', 'uang_jalan_tambahan'])
            ->groupBy('id_penugasan')
            ->map(fn ($g) => $g->map(fn ($r) => [
                'lokasi'              => $r->lokasi,
                'uang_jalan_tambahan' => (float) $r->uang_jalan_tambahan,
            ])->values()->all())
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
                'p.keterangan',
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
