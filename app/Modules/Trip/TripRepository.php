<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Trip\Contracts\TripRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TripRepository implements TripRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit, ?string $idJadwal = null, ?string $idPenugasan = null, ?string $idSupir = null, ?string $search = null, ?string $status = null, ?string $idProyek = null, ?string $tanggalDari = null, ?string $tanggalSampai = null, ?string $sumber = null): LengthAwarePaginator
    {
        $paginator = TripModel::active()
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->when($idJadwal, fn ($q, $v) => $q->where('trip.id_jadwal', $v))
            ->when($idPenugasan, fn ($q, $v) => $q->where('jk.id_penugasan', $v))
            ->when($idSupir, fn ($q, $v) => $q->where('p.id_supir', $v))
            ->when($status, fn ($q, $v) => $q->whereIn('trip.status', $this->pecahStatus($v)))
            ->when($idProyek, fn ($q, $v) => $q->where('pr.id_proyek', $v))
            ->when($sumber, fn ($q, $v) => $q->where('p.sumber', $v))
            ->when($tanggalDari, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, trip.dibuat_pada)) >= ?', [$v]))
            ->when($tanggalSampai, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, trip.dibuat_pada)) <= ?', [$v]))
            ->when($search, function ($q) use ($search) {
                $q->leftJoin('rute as r', 'jk.id_rute', '=', 'r.id_rute')
                    ->leftJoin('armada as a', 'p.id_armada', '=', 'a.id_armada')
                    ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
                    ->leftJoin('supir as s', 'p.id_supir', '=', 's.id_supir')
                    ->leftJoin('supir_vendor as sv', 'p.id_supir_vendor', '=', 'sv.id_supir_vendor')
                    ->where(function ($q2) use ($search) {
                        $q2->where('r.nama_rute', 'like', "%{$search}%")
                            ->orWhere('jk.rute', 'like', "%{$search}%")
                            ->orWhere('a.nopol', 'like', "%{$search}%")
                            ->orWhere('av.nopol', 'like', "%{$search}%")
                            ->orWhere('s.nama', 'like', "%{$search}%")
                            ->orWhere('sv.nama', 'like', "%{$search}%")
                            ->orWhere('pr.nama_proyek', 'like', "%{$search}%")
                            ->orWhere('pr.kode_proyek', 'like', "%{$search}%");
                    });
            })
            ->select('trip.*')
            ->orderBy('trip.dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachJadwalDetail($paginator->getCollection());

        return $paginator;
    }

    /** Ringkasan trip dikelompokkan per proyek (nama, kode, klien, jumlah trip) — dipaginasi per proyek. */
    public function paginateProyekSummary(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('klien as k', 'k.id_klien', '=', 'pr.id_klien')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->when($status, fn ($q, $v) => $q->whereIn('t.status', $this->pecahStatus($v)))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('pr.nama_proyek', 'like', "%{$search}%")
                        ->orWhere('pr.kode_proyek', 'like', "%{$search}%")
                        ->orWhere('k.nama_klien', 'like', "%{$search}%");
                });
            })
            ->groupBy('pr.id_proyek', 'pr.kode_proyek', 'pr.nama_proyek', 'k.nama_klien')
            ->select(
                'pr.id_proyek',
                'pr.kode_proyek',
                'pr.nama_proyek',
                'k.nama_klien',
                DB::raw('count(t.id_trip) as jumlah_trip'),
                DB::raw('max(t.dibuat_pada) as aktivitas_terakhir'),
            )
            ->orderByDesc('aktivitas_terakhir')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /** Status bisa berisi beberapa nilai dipisah koma (mis. "belum_mulai,berjalan"). */
    private function pecahStatus(string $status): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $status))));
    }

    public function exists(string $idTrip): bool
    {
        return TripModel::active()->where('id_trip', $idTrip)->exists();
    }

    public function findById(string $id): ?TripModel
    {
        $record = TripModel::active()->find($id);

        if ($record !== null) {
            $this->attachJadwalDetail(collect([$record]));
        }

        return $record;
    }

    /**
     * Attach rute/waktu_berangkat/supir_nama/armada_nopol via setRelation() —
     * never raw property assignment, karena instance ini bisa mengalir ke
     * repo->update() (lihat TripService::checkin/checkout/update/batalkan)
     * dan raw assignment akan ikut ter-UPDATE sebagai kolom SQL yang tidak ada.
     */
    private function attachJadwalDetail(Collection $records): void
    {
        $idJadwalList = $records->pluck('id_jadwal')->unique()->filter()->values();
        if ($idJadwalList->isEmpty()) {
            return;
        }

        $jadwalRows = DB::table('jadwal_keberangkatan')
            ->whereIn('id_jadwal', $idJadwalList)
            ->select('id_jadwal', 'id_penugasan', 'id_rute', 'waktu_berangkat', 'rute')
            ->get()
            ->keyBy('id_jadwal');

        $idRuteList      = $jadwalRows->pluck('id_rute')->unique()->filter()->values();
        $idPenugasanList = $jadwalRows->pluck('id_penugasan')->unique()->filter()->values();

        $ruteMap = $idRuteList->isEmpty() ? collect()
            : DB::table('rute')->whereIn('id_rute', $idRuteList)->pluck('nama_rute', 'id_rute');

        $penugasanRows = $idPenugasanList->isEmpty() ? collect() : DB::table('penugasan')
            ->whereIn('id_penugasan', $idPenugasanList)
            ->select('id_penugasan', 'id_armada', 'id_supir', 'id_armada_vendor', 'id_supir_vendor', 'id_proyek', 'sumber', 'id_kontrak_vendor')
            ->get()
            ->keyBy('id_penugasan');

        $idArmadaList        = $penugasanRows->pluck('id_armada')->unique()->filter()->values();
        $idSupirList         = $penugasanRows->pluck('id_supir')->unique()->filter()->values();
        $idArmadaVendorList  = $penugasanRows->pluck('id_armada_vendor')->unique()->filter()->values();
        $idSupirVendorList   = $penugasanRows->pluck('id_supir_vendor')->unique()->filter()->values();
        $idProyekList        = $penugasanRows->pluck('id_proyek')->unique()->filter()->values();
        $idKontrakVendorList = $penugasanRows->pluck('id_kontrak_vendor')->unique()->filter()->values();

        $armadaMap = $idArmadaList->isEmpty() ? collect()
            : DB::table('armada')->whereIn('id_armada', $idArmadaList)->pluck('nopol', 'id_armada');
        $supirMap = $idSupirList->isEmpty() ? collect()
            : DB::table('supir')->whereIn('id_supir', $idSupirList)->pluck('nama', 'id_supir');
        $armadaVendorMap = $idArmadaVendorList->isEmpty() ? collect()
            : DB::table('armada_vendor')->whereIn('id_armada_vendor', $idArmadaVendorList)->pluck('nopol', 'id_armada_vendor');
        $supirVendorMap = $idSupirVendorList->isEmpty() ? collect()
            : DB::table('supir_vendor')->whereIn('id_supir_vendor', $idSupirVendorList)->pluck('nama', 'id_supir_vendor');
        $proyekMap = $idProyekList->isEmpty() ? collect() : DB::table('proyek as pr')
            ->leftJoin('klien as k', 'k.id_klien', '=', 'pr.id_klien')
            ->whereIn('pr.id_proyek', $idProyekList)
            ->select('pr.id_proyek', 'pr.kode_proyek', 'pr.nama_proyek', 'k.nama_klien')
            ->get()
            ->keyBy('id_proyek');
        $kontrakVendorMap = $idKontrakVendorList->isEmpty() ? collect()
            : DB::table('kontrak_vendor')->whereIn('id_kontrak_vendor', $idKontrakVendorList)
                ->get(['id_kontrak_vendor', 'id_vendor', 'mekanisme'])->keyBy('id_kontrak_vendor');
        $idVendorList = $kontrakVendorMap->pluck('id_vendor')->unique()->filter()->values();
        $vendorMap = $idVendorList->isEmpty() ? collect()
            : DB::table('vendor')->whereIn('id_vendor', $idVendorList)->pluck('nama_vendor', 'id_vendor');

        // Supir shift: penugasan sengaja tanpa id_armada — armada hariannya
        // ditentukan lewat alokasi_armada (lihat AlokasiArmadaService::alokasikan()),
        // bukan kolom penugasan. Kumpulkan pasangan (id_supir, tanggal keberangkatan)
        // yang butuh fallback ini supaya nopol tidak kosong di riwayat trip.
        $tanggalUntukAlokasi = static function (?object $jadwal, object $record): string {
            $sumber = $jadwal->waktu_berangkat ?? $record->dibuat_pada;
            return substr((string) $sumber, 0, 10);
        };
        $idSupirUntukAlokasi = [];
        foreach ($records as $record) {
            $jadwal    = $jadwalRows->get($record->id_jadwal);
            $penugasan = $jadwal !== null ? $penugasanRows->get($jadwal->id_penugasan) : null;
            if ($penugasan !== null && empty($penugasan->id_armada) && !empty($penugasan->id_supir)) {
                $idSupirUntukAlokasi[] = $penugasan->id_supir;
            }
        }
        $idSupirUntukAlokasi = array_values(array_unique($idSupirUntukAlokasi));

        $alokasiNopolMap = [];
        if ($idSupirUntukAlokasi !== []) {
            DB::table('alokasi_armada as aa')
                ->join('armada as arm', 'arm.id_armada', '=', 'aa.id_armada')
                ->whereNull('aa.dihapus_pada')
                ->whereIn('aa.id_supir', $idSupirUntukAlokasi)
                ->select('aa.id_supir', 'aa.tanggal', 'arm.nopol')
                ->get()
                ->each(function ($row) use (&$alokasiNopolMap) {
                    $alokasiNopolMap[$row->id_supir . '|' . substr((string) $row->tanggal, 0, 10)] = $row->nopol;
                });
        }

        foreach ($records as $record) {
            $jadwal    = $jadwalRows->get($record->id_jadwal);
            $penugasan = $jadwal !== null ? $penugasanRows->get($jadwal->id_penugasan) : null;

            $armadaNopol = $penugasan !== null
                ? ($armadaMap->get($penugasan->id_armada) ?? $armadaVendorMap->get($penugasan->id_armada_vendor))
                : null;
            if ($armadaNopol === null && $penugasan !== null && empty($penugasan->id_armada) && !empty($penugasan->id_supir)) {
                $armadaNopol = $alokasiNopolMap[$penugasan->id_supir . '|' . $tanggalUntukAlokasi($jadwal, $record)] ?? null;
            }
            $supirNama = $penugasan !== null
                ? ($supirMap->get($penugasan->id_supir) ?? $supirVendorMap->get($penugasan->id_supir_vendor))
                : null;
            $proyek = $penugasan !== null ? $proyekMap->get($penugasan->id_proyek) : null;

            $ruteNama = $jadwal !== null
                ? ($ruteMap->get($jadwal->id_rute) ?? $jadwal->rute)
                : null;

            $kontrak = $penugasan !== null && $penugasan->id_kontrak_vendor !== null
                ? $kontrakVendorMap->get($penugasan->id_kontrak_vendor)
                : null;
            $vendorNama = $kontrak?->id_vendor !== null ? $vendorMap->get($kontrak->id_vendor) : null;

            $record->setRelation('rute', $ruteNama);
            $record->setRelation('waktu_berangkat', $jadwal->waktu_berangkat ?? null);
            $record->setRelation('armada_nopol', $armadaNopol);
            $record->setRelation('supir_nama', $supirNama);
            $record->setRelation('id_proyek', $proyek->id_proyek ?? null);
            $record->setRelation('kode_proyek', $proyek->kode_proyek ?? null);
            $record->setRelation('nama_proyek', $proyek->nama_proyek ?? null);
            $record->setRelation('nama_klien', $proyek->nama_klien ?? null);
            $record->setRelation('sumber', $penugasan->sumber ?? 'internal');
            $record->setRelation('vendor_nama', $vendorNama);
            $record->setRelation('mekanisme', $kontrak?->mekanisme);
            $record->setRelation('id_penugasan', $penugasan?->id_penugasan);
        }
    }

    public function findByJadwal(string $idJadwal): ?TripModel
    {
        return TripModel::active()->where('id_jadwal', $idJadwal)->first();
    }

    public function findPenugasanDariTrip(string $idTrip): ?object
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->where('t.id_trip', $idTrip)
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->select('p.*')
            ->first();
    }

    public function findPenugasanDariJadwal(string $idJadwal, string $idPerusahaan): ?object
    {
        return DB::table('jadwal_keberangkatan as jk')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('jk.id_jadwal', $idJadwal)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->select('p.*')
            ->first();
    }

    public function adaTripNonFinalUntukPenugasan(string $idPenugasan, ?string $excludeTripId = null): bool
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->where('jk.id_penugasan', $idPenugasan)
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNotIn('t.status', ['selesai', 'dibatalkan'])
            ->when($excludeTripId, fn ($q, $v) => $q->where('t.id_trip', '!=', $v))
            ->exists();
    }

    public function findPenugasanMilikPerusahaan(string $idPenugasan, string $idPerusahaan): ?object
    {
        return DB::table('penugasan as p')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->where('p.id_penugasan', $idPenugasan)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->select('p.*')
            ->first();
    }

    public function adaTripBerjalanUntukAktorLain(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor, string $excludeTripId): bool
    {
        if (!$idArmada && !$idSupir && !$idArmadaVendor && !$idSupirVendor) {
            return false;
        }

        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->where('t.status', 'berjalan')
            ->where('t.id_trip', '!=', $excludeTripId)
            ->where(function ($q) use ($idArmada, $idSupir, $idArmadaVendor, $idSupirVendor) {
                if ($idArmada) {
                    $q->orWhere('p.id_armada', $idArmada);
                }
                if ($idSupir) {
                    $q->orWhere('p.id_supir', $idSupir);
                }
                if ($idArmadaVendor) {
                    $q->orWhere('p.id_armada_vendor', $idArmadaVendor);
                }
                if ($idSupirVendor) {
                    $q->orWhere('p.id_supir_vendor', $idSupirVendor);
                }
            })
            ->lockForUpdate()
            ->exists();
    }

    public function findTripAktifUntukAktor(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor): ?object
    {
        if (!$idArmada && !$idSupir && !$idArmadaVendor && !$idSupirVendor) {
            return null;
        }

        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNotIn('t.status', ['selesai', 'dibatalkan'])
            ->where(function ($q) use ($idArmada, $idSupir, $idArmadaVendor, $idSupirVendor) {
                if ($idArmada) {
                    $q->orWhere('p.id_armada', $idArmada);
                }
                if ($idSupir) {
                    $q->orWhere('p.id_supir', $idSupir);
                }
                if ($idArmadaVendor) {
                    $q->orWhere('p.id_armada_vendor', $idArmadaVendor);
                }
                if ($idSupirVendor) {
                    $q->orWhere('p.id_supir_vendor', $idSupirVendor);
                }
            })
            ->select('t.id_trip', 't.status', 't.dibuat_pada', 'pr.nama_proyek')
            ->lockForUpdate()
            ->first();
    }

    public function create(array $data): TripModel
    {
        return TripModel::create($data);
    }

    public function update(TripModel $model, array $data): TripModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(TripModel $model): void
    {
        $model->softDelete();
    }

    public function rekapBiaya(string $idTrip): array
    {
        $laporan = DB::table('laporan_perjalanan')
            ->where('id_trip', $idTrip)->whereNull('dihapus_pada')->first();

        $estimasi = DB::table('trip')
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->where('trip.id_trip', $idTrip)
            ->whereNull('p.dihapus_pada')
            ->value('p.estimasi_biaya');

        $items = $laporan
            ? DB::table('biaya_lain_trip')->where('id_laporan', $laporan->id_laporan)
                ->whereNull('dihapus_pada')
                ->select('id_biaya_lain', 'nama_biaya', 'nominal')->get()
            : collect();

        $totalBbm  = (float) ($laporan->biaya_bbm ?? 0);
        $totalUj   = (float) ($laporan->uang_jalan ?? 0);
        $totalTol  = (float) ($laporan->uang_tol ?? 0);
        $totalLain = (float) $items->sum('nominal');
        $total     = $totalBbm + $totalUj + $totalTol + $totalLain;

        return [
            'total_bbm'         => $totalBbm,
            'total_uang_jalan'  => $totalUj,
            'total_uang_tol'    => $totalTol,
            'total_biaya_lain'  => $totalLain,
            'total_keseluruhan' => $total,
            'estimasi_biaya'    => $estimasi !== null ? (float) $estimasi : null,
            'selisih'           => $estimasi !== null ? (float) $estimasi - $total : null,
            'jarak_tempuh_km'   => $laporan && $laporan->jarak_tempuh_km !== null ? (float) $laporan->jarak_tempuh_km : null,
            'items'             => $items->map(fn ($i) => ['id_biaya_lain' => $i->id_biaya_lain, 'nama_biaya' => $i->nama_biaya, 'nominal' => (float) $i->nominal])->values()->toArray(),
        ];
    }

    public function milikPerusahaan(string $idTrip, string $idPerusahaan): bool
    {
        return DB::table('trip')
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('trip.id_trip', $idTrip)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('trip.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->exists();
    }

    public function namaKlienPerProyek(array $idProyekList): array
    {
        if ($idProyekList === []) {
            return [];
        }

        return DB::table('proyek as pr')
            ->join('klien as k', 'k.id_klien', '=', 'pr.id_klien')
            ->whereIn('pr.id_proyek', $idProyekList)
            ->pluck('k.nama_klien', 'pr.id_proyek')
            ->all();
    }

    public function tripAktifSupir(string $idSupir): array
    {
        $rows = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'p.id_penugasan', '=', 'jk.id_penugasan')
            ->where('p.id_supir', $idSupir)
            ->whereIn('t.status', ['belum_mulai', 'berjalan'])
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->select('jk.id_penugasan', 't.id_trip', 't.status', 't.waktu_checkin', 't.dibuat_pada')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[$row->id_penugasan]) || $row->status === 'berjalan') {
                $map[$row->id_penugasan] = [
                    'id_trip' => $row->id_trip,
                    'status'  => $row->status,
                    'tanggal' => substr((string) ($row->waktu_checkin ?? $row->dibuat_pada), 0, 10),
                ];
            }
        }

        return $map;
    }

    public function tripSelesaiPerPenugasanTanggal(array $idPenugasanList): array
    {
        if ($idPenugasanList === []) {
            return [];
        }

        $rows = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->whereIn('jk.id_penugasan', $idPenugasanList)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->select('jk.id_penugasan', 't.waktu_checkin', 't.waktu_checkout', 't.dibuat_pada')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $tanggal = substr((string) ($row->waktu_checkin ?? $row->waktu_checkout ?? $row->dibuat_pada), 0, 10);
            $kunci = $row->id_penugasan . '|' . $tanggal;
            $map[$kunci] = ($map[$kunci] ?? 0) + 1;
        }

        return $map;
    }

    public function laporanTerisiPerPenugasanTanggal(array $idPenugasanList): array
    {
        if ($idPenugasanList === []) {
            return [];
        }

        $rows = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('laporan_perjalanan as lp', 'lp.id_trip', '=', 't.id_trip')
            ->whereIn('jk.id_penugasan', $idPenugasanList)
            ->where('t.status', 'selesai')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('lp.dihapus_pada')
            ->select('jk.id_penugasan', 't.id_trip', 't.waktu_checkin', 't.waktu_checkout', 't.dibuat_pada')
            ->distinct()
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $tanggal = substr((string) ($row->waktu_checkin ?? $row->waktu_checkout ?? $row->dibuat_pada), 0, 10);
            $kunci = $row->id_penugasan . '|' . $tanggal;
            $map[$kunci] = ($map[$kunci] ?? 0) + 1;
        }

        return $map;
    }

    /**
     * Trip per (supir, tanggal) UNTUK SATU PROYEK — dipakai Papan Jadwal
     * supaya sel jadwal bisa ditandai "sedang jalan"/"selesai". Sengaja
     * di-scope ke id_proyek supaya trip supir yang sama di proyek lain tidak
     * ikut menandai sel proyek ini. 'belum_mulai' dianggap 'berjalan' (armada
     * sudah dikunci). Satu supir bisa punya LEBIH DARI SATU trip di tanggal
     * yang sama (lebih dari 1 rit sehari) — semuanya dikembalikan, diurutkan
     * dari yang paling lama dibuat, supaya pemanggil bisa tampilkan semuanya
     * alih-alih cuma satu.
     *
     * @return array<string, array{status: 'berjalan'|'selesai', id_trip: string}[]> kunci "idSupir|tanggal"
     */
    public function statusTripPerSupirTanggal(string $idProyek, array $idSupirList, string $dari, string $sampai): array
    {
        if ($idSupirList === []) {
            return [];
        }

        $rows = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->where('p.id_proyek', $idProyek)
            ->whereIn('p.id_supir', $idSupirList)
            ->whereIn('t.status', ['belum_mulai', 'berjalan', 'selesai'])
            ->select('p.id_supir', 't.id_trip', 't.status', 't.waktu_checkin', 't.waktu_checkout', 't.dibuat_pada')
            ->orderBy('t.dibuat_pada')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $tanggal = substr((string) ($row->waktu_checkin ?? $row->waktu_checkout ?? $row->dibuat_pada), 0, 10);
            if ($tanggal < $dari || $tanggal > $sampai) {
                continue;
            }

            $kunci = $row->id_supir . '|' . $tanggal;
            $statusSaatIni = in_array($row->status, ['belum_mulai', 'berjalan'], true) ? 'berjalan' : 'selesai';

            $map[$kunci][] = ['status' => $statusSaatIni, 'id_trip' => $row->id_trip];
        }

        return $map;
    }

    public function salinTitikDropDariPenugasan(string $idPenugasan, string $idTrip): void
    {
        $rows = DB::table('titik_drop_penugasan')
            ->where('id_penugasan', $idPenugasan)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['urutan', 'lokasi']);

        foreach ($rows as $row) {
            DB::table('titik_drop_trip')->insert(RecordHelper::stampCreate([
                'id_trip' => $idTrip,
                'urutan'  => (int) $row->urutan,
                'lokasi'  => $row->lokasi,
            ], 'id_titik_drop'));
        }
    }

    public function syncTitikDropTrip(string $idTrip, array $lokasiList): void
    {
        DB::table('titik_drop_trip')
            ->where('id_trip', $idTrip)->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());
        foreach (array_values($lokasiList) as $i => $lokasi) {
            DB::table('titik_drop_trip')->insert(RecordHelper::stampCreate([
                'id_trip' => $idTrip,
                'urutan'  => $i + 1,
                'lokasi'  => trim((string) $lokasi),
            ], 'id_titik_drop'));
        }
    }

    public function titikDropTripBanyak(array $idTrips): array
    {
        if ($idTrips === []) {
            return [];
        }
        return DB::table('titik_drop_trip')
            ->whereIn('id_trip', $idTrips)->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_trip', 'lokasi'])
            ->groupBy('id_trip')
            ->map(fn ($g) => $g->pluck('lokasi')->all())
            ->all();
    }

    public function titikDropTrip(string $idTrip): array
    {
        return $this->titikDropTripBanyak([$idTrip])[$idTrip] ?? [];
    }

    public function tripPunyaFakturAktif(string $idTrip): bool
    {
        return DB::table('faktur_trip as ft')
            ->join('faktur as f', 'f.id_faktur', '=', 'ft.id_faktur')
            ->where('ft.id_trip', $idTrip)
            ->whereNull('ft.dihapus_pada')
            ->whereNull('f.dihapus_pada')
            ->where('f.status', '!=', 'batal')
            ->exists();
    }
}
