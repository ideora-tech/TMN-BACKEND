<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\IntervalPerawatan\IntervalLabelBuilder;
use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PerawatanArmadaRepository implements PerawatanArmadaRepositoryInterface
{
    private const COLUMNS = [
        'perawatan_armada.id_perawatan', 'perawatan_armada.id_armada', 'perawatan_armada.id_supplier',
        'perawatan_armada.id_interval_perawatan', 'perawatan_armada.tanggal',
        'perawatan_armada.biaya', 'perawatan_armada.km_odometer',
        'perawatan_armada.status', 'perawatan_armada.alasan_batal',
        'perawatan_armada.jadwal_servis_berikutnya', 'perawatan_armada.keterangan',
        'perawatan_armada.dibuat_pada', 'perawatan_armada.dibuat_oleh',
        'perawatan_armada.diubah_pada', 'perawatan_armada.diubah_oleh',
        'perawatan_armada.dihapus_pada', 'perawatan_armada.dihapus_oleh',
    ];

    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->orderByDesc('tanggal')
            ->orderByDesc('dibuat_pada')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status, bool $jatuhTempo = false, ?string $search = null, ?string $tanggalDari = null, ?string $tanggalSampai = null): LengthAwarePaginator
    {
        $batas = now()->addDays(30)->toDateString();

        return DB::table('perawatan_armada')
            ->join('armada', 'armada.id_armada', '=', 'perawatan_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('perawatan_armada.dihapus_pada')
            ->whereNull('armada.dihapus_pada')
            ->when($idArmada, fn ($q, $v) => $q->where('perawatan_armada.id_armada', $v))
            ->when($status, fn ($q, $v) => $q->whereIn('perawatan_armada.status', array_map('trim', explode(',', $v))))
            ->when($tanggalDari, fn ($q, $v) => $q->whereDate('perawatan_armada.tanggal', '>=', $v))
            ->when($tanggalSampai, fn ($q, $v) => $q->whereDate('perawatan_armada.tanggal', '<=', $v))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('perawatan_armada.jenis_perawatan', 'like', "%{$search}%")
                   ->orWhere('perawatan_armada.keterangan', 'like', "%{$search}%")
                   ->orWhere('armada.nopol', 'like', "%{$search}%");
            }))
            ->when($jatuhTempo, fn ($q) => $q
                ->whereNotNull('perawatan_armada.jadwal_servis_berikutnya')
                ->where('perawatan_armada.jadwal_servis_berikutnya', '<=', $batas)
                ->whereRaw("perawatan_armada.id_perawatan = (
                    SELECT p2.id_perawatan FROM perawatan_armada p2
                    WHERE p2.id_armada = perawatan_armada.id_armada AND p2.dihapus_pada IS NULL
                      AND p2.status != 'dibatalkan'
                    ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                    LIMIT 1
                )"))
            ->orderByRaw('MAX(perawatan_armada.tanggal) OVER (PARTITION BY perawatan_armada.id_armada) DESC')
            ->orderBy('armada.nopol')
            ->orderByDesc('perawatan_armada.tanggal')
            ->orderByDesc('perawatan_armada.dibuat_pada')
            ->select(array_merge(self::COLUMNS, ['armada.nopol as armada_nopol', 'armada.merk as armada_merk']))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('perawatan_armada')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $id)
            ->first();
    }

    public function milikPerusahaan(string $idPerawatan, string $idPerusahaan): bool
    {
        return DB::table('perawatan_armada')
            ->join('armada', function ($join) use ($idPerusahaan) {
                $join->on('armada.id_armada', '=', 'perawatan_armada.id_armada')
                    ->where('armada.id_perusahaan', $idPerusahaan)
                    ->whereNull('armada.dihapus_pada');
            })
            ->whereNull('perawatan_armada.dihapus_pada')
            ->where('perawatan_armada.id_perawatan', $idPerawatan)
            ->exists();
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    private function subTotalSparepart()
    {
        return DB::table('perawatan_sparepart')
            ->selectRaw('id_perawatan, SUM(qty * harga) as total_sparepart, SUM(qty) as qty_sparepart')
            ->whereNull('dihapus_pada')
            ->groupBy('id_perawatan');
    }

    public function rekapPerUnit(string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        return DB::table('perawatan_armada as p')
            ->join('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoinSub($this->subTotalSparepart(), 'sp', 'sp.id_perawatan', '=', 'p.id_perawatan')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->where('p.status', '!=', 'dibatalkan')
            ->when($dari, fn ($q, $v) => $q->whereDate('p.tanggal', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->whereDate('p.tanggal', '<=', $v))
            ->groupBy('a.id_armada', 'a.nopol', 'a.merk')
            ->orderBy('a.nopol')
            ->selectRaw('a.id_armada, a.nopol, a.merk,
                COUNT(p.id_perawatan) as jumlah_perawatan,
                COALESCE(SUM(p.biaya), 0) as biaya_jasa,
                COALESCE(SUM(sp.total_sparepart), 0) as biaya_sparepart,
                COALESCE(SUM(sp.qty_sparepart), 0) as qty_sparepart,
                MAX(p.km_odometer) as km_terakhir,
                MAX(p.tanggal) as tanggal_terakhir')
            ->get()
            ->all();
    }

    private function queryRentang(?string $dari, ?string $sampai)
    {
        return DB::table('perawatan_armada as p')
            ->join('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoinSub($this->subTotalSparepart(), 'sp', 'sp.id_perawatan', '=', 'p.id_perawatan')
            ->leftJoin('interval_perawatan as ip', 'ip.id_interval_perawatan', '=', 'p.id_interval_perawatan')
            ->leftJoin('supplier as s', 's.id_supplier', '=', 'p.id_supplier')
            ->whereNull('p.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->where('p.status', '!=', 'dibatalkan')
            ->when($dari, fn ($q, $v) => $q->whereDate('p.tanggal', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->whereDate('p.tanggal', '<=', $v))
            ->selectRaw('p.*, a.nopol, a.merk, s.nama as nama_supplier,
                COALESCE(sp.total_sparepart, 0) as total_sparepart,
                COALESCE(sp.qty_sparepart, 0) as qty_sparepart,
                ip.interval_km, ip.interval_bulan');
    }

    private function tempelLabelJenis($row): object
    {
        $row->jenis_perawatan = IntervalLabelBuilder::buildOrFallback(
            $row->interval_km !== null ? (int) $row->interval_km : null,
            $row->interval_bulan !== null ? (int) $row->interval_bulan : null,
        );
        return $row;
    }

    /** @return object[] */
    public function listByArmadaRentang(string $idArmada, ?string $dari = null, ?string $sampai = null): array
    {
        return $this->queryRentang($dari, $sampai)
            ->where('p.id_armada', $idArmada)
            ->orderByDesc('p.tanggal')
            ->orderByDesc('p.dibuat_pada')
            ->get()
            ->map(fn ($row) => $this->tempelLabelJenis($row))
            ->all();
    }

    public function listRentangPerusahaan(string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        return $this->queryRentang($dari, $sampai)
            ->where('a.id_perusahaan', $idPerusahaan)
            ->orderBy('a.nopol')
            ->orderByDesc('p.tanggal')
            ->orderByDesc('p.dibuat_pada')
            ->get()
            ->map(fn ($row) => $this->tempelLabelJenis($row))
            ->all();
    }

    public function linesByPerawatanIds(array $idPerawatanList): array
    {
        if ($idPerawatanList === []) {
            return [];
        }

        return DB::table('perawatan_sparepart as ps')
            ->leftJoin('sparepart as s', 's.id_sparepart', '=', 'ps.id_sparepart')
            ->whereNull('ps.dihapus_pada')
            ->whereIn('ps.id_perawatan', $idPerawatanList)
            ->orderBy('ps.dibuat_pada')
            ->get(['ps.id_perawatan', 'ps.id_sparepart', 'ps.nama_sparepart', 'ps.sumber', 'ps.qty', 'ps.harga', 's.kode as kode_sparepart', 's.satuan'])
            ->all();
    }

    public function rekapSparepart(string $idPerusahaan, ?string $idArmada = null, ?string $dari = null, ?string $sampai = null): array
    {
        return DB::table('perawatan_sparepart as ps')
            ->join('perawatan_armada as p', 'p.id_perawatan', '=', 'ps.id_perawatan')
            ->join('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoin('sparepart as s', 's.id_sparepart', '=', 'ps.id_sparepart')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->whereNull('ps.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->where('p.status', '!=', 'dibatalkan')
            ->when($idArmada, fn ($q, $v) => $q->where('a.id_armada', $v))
            ->when($dari, fn ($q, $v) => $q->whereDate('p.tanggal', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->whereDate('p.tanggal', '<=', $v))
            ->groupByRaw('a.id_armada, a.nopol, a.merk, COALESCE(ps.id_sparepart, ps.nama_sparepart)')
            ->orderBy('a.nopol')
            ->orderByDesc('total_biaya')
            ->selectRaw('a.id_armada, a.nopol, a.merk,
                MAX(ps.id_sparepart) as id_sparepart,
                MAX(ps.nama_sparepart) as nama_sparepart,
                MAX(s.kode) as kode_sparepart,
                MAX(s.satuan) as satuan,
                SUM(ps.qty) as total_qty,
                SUM(ps.qty * ps.harga) as total_biaya,
                COUNT(DISTINCT p.id_perawatan) as jumlah_perawatan,
                MAX(p.tanggal) as terakhir_dipakai,
                SUM(CASE WHEN ps.sumber = \'stok_sendiri\' THEN ps.qty ELSE 0 END) as qty_stok,
                SUM(CASE WHEN ps.sumber = \'bengkel\' THEN ps.qty ELSE 0 END) as qty_bengkel')
            ->get()
            ->all();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_perawatan');
        DB::table('perawatan_armada')->insert($data);
        return $this->findById($data['id_perawatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('perawatan_armada')
            ->where('id_perawatan', $record->id_perawatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_perawatan);
    }

    public function delete(object $record): void
    {
        DB::table('perawatan_armada')
            ->where('id_perawatan', $record->id_perawatan)
            ->update(RecordHelper::stampDelete());
    }

    private const LINE_COLUMNS = [
        'id_perawatan_sparepart', 'id_perawatan', 'id_sparepart', 'nama_sparepart', 'sumber', 'qty', 'harga',
    ];

    public function getActiveLines(string $idPerawatan): array
    {
        return DB::table('perawatan_sparepart')
            ->select(self::LINE_COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $idPerawatan)
            ->orderBy('dibuat_pada')
            ->get()
            ->all();
    }

    public function insertLine(array $data): void
    {
        DB::table('perawatan_sparepart')->insert(RecordHelper::stampCreate($data, 'id_perawatan_sparepart'));
    }

    public function listBukti(string $idPerawatan): array
    {
        return DB::table('perawatan_armada_bukti')
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $idPerawatan)
            ->orderBy('dibuat_pada')
            ->select('id_bukti', 'url_file', 'nama_asli')
            ->get()
            ->all();
    }

    public function insertBukti(array $data): void
    {
        DB::table('perawatan_armada_bukti')->insert(RecordHelper::stampCreate($data, 'id_bukti'));
    }

    public function findBukti(string $idPerawatan, string $idBukti): ?object
    {
        return DB::table('perawatan_armada_bukti')
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $idPerawatan)
            ->where('id_bukti', $idBukti)
            ->first();
    }

    public function softDeleteBukti(string $idBukti): void
    {
        DB::table('perawatan_armada_bukti')
            ->where('id_bukti', $idBukti)
            ->update(RecordHelper::stampDelete());
    }

    public function softDeleteLines(string $idPerawatan): void
    {
        DB::table('perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $idPerawatan)
            ->update(RecordHelper::stampDelete());
    }

    public function getSparepartForUpdate(string $idSparepart): ?object
    {
        return DB::table('sparepart')
            ->select(['id_sparepart', 'nama', 'stok'])
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->lockForUpdate()
            ->first();
    }

    public function setSparepartStok(string $idSparepart, int $stokBaru): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $idSparepart)
            ->update(RecordHelper::stampUpdate(['stok' => $stokBaru]));
    }

    public function insertSparepartMutasi(array $data): void
    {
        DB::table('sparepart_mutasi')->insert(RecordHelper::stampCreate($data, 'id_mutasi'));
    }

    public function getSparepartNama(string $idSparepart): ?string
    {
        $nama = DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->value('nama');

        return $nama !== null ? (string) $nama : null;
    }

    public function supplierMilik(string $idPerusahaan, string $idSupplier): bool
    {
        return DB::table('supplier')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_supplier', $idSupplier)
            ->exists();
    }

    public function getSupplierNama(string $idSupplier): ?string
    {
        $nama = DB::table('supplier')
            ->whereNull('dihapus_pada')
            ->where('id_supplier', $idSupplier)
            ->value('nama');

        return $nama !== null ? (string) $nama : null;
    }

    public function supplierUntukBanyak(array $idSupplierList): array
    {
        if ($idSupplierList === []) {
            return [];
        }

        return DB::table('supplier')
            ->whereNull('dihapus_pada')
            ->whereIn('id_supplier', $idSupplierList)
            ->pluck('nama', 'id_supplier')
            ->all();
    }

    /**
     * Catatan terbaru (status selesai) per id_interval_perawatan untuk 1 armada.
     * Catatan tanpa tautan paket (id_interval_perawatan NULL) sengaja dikecualikan
     * — tidak ikut mereset jadwal paket manapun.
     */
    public function getLatestPerIntervalByArmada(string $idArmada): array
    {
        return DB::table('perawatan_armada as p')
            ->whereNull('p.dihapus_pada')
            ->where('p.id_armada', $idArmada)
            ->where('p.status', 'selesai')
            ->whereNotNull('p.id_interval_perawatan')
            ->whereRaw('p.id_perawatan = (
                SELECT p2.id_perawatan FROM perawatan_armada p2
                WHERE p2.id_armada = p.id_armada
                  AND p2.id_interval_perawatan = p.id_interval_perawatan
                  AND p2.status = \'selesai\'
                  AND p2.dihapus_pada IS NULL
                ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                LIMIT 1
            )')
            ->get(['p.id_interval_perawatan', 'p.tanggal', 'p.jadwal_servis_berikutnya', 'p.km_odometer'])
            ->all();
    }

    /** Odometer terakhir yang diketahui untuk 1 armada (dari catatan servis ber-km). */
    public function kmOdometerTerakhir(string $idArmada): ?int
    {
        $km = DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->where('status', '!=', 'dibatalkan')
            ->whereNotNull('km_odometer')
            ->max('km_odometer');

        return $km !== null ? (int) $km : null;
    }

    public function getLatestPerIntervalByArmadaIds(array $armadaIds): array
    {
        if (empty($armadaIds)) {
            return [];
        }

        return DB::table('perawatan_armada as p')
            ->whereNull('p.dihapus_pada')
            ->whereIn('p.id_armada', $armadaIds)
            ->where('p.status', 'selesai')
            ->whereNotNull('p.id_interval_perawatan')
            ->whereRaw('p.id_perawatan = (
                SELECT p2.id_perawatan FROM perawatan_armada p2
                WHERE p2.id_armada = p.id_armada
                  AND p2.id_interval_perawatan = p.id_interval_perawatan
                  AND p2.status = \'selesai\'
                  AND p2.dihapus_pada IS NULL
                ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                LIMIT 1
            )')
            ->get(['p.id_armada', 'p.id_interval_perawatan', 'p.tanggal', 'p.jadwal_servis_berikutnya', 'p.km_odometer'])
            ->all();
    }

    public function kmOdometerTerakhirByArmadaIds(array $armadaIds): array
    {
        if (empty($armadaIds)) {
            return [];
        }

        return DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->whereIn('id_armada', $armadaIds)
            ->where('status', '!=', 'dibatalkan')
            ->whereNotNull('km_odometer')
            ->groupBy('id_armada')
            ->selectRaw('id_armada, MAX(km_odometer) as km_terakhir')
            ->pluck('km_terakhir', 'id_armada')
            ->all();
    }

    public function getServisTerakhirSelesaiByArmadaIds(array $armadaIds): array
    {
        if (empty($armadaIds)) {
            return [];
        }

        return DB::table('perawatan_armada as p')
            ->leftJoin('interval_perawatan as ip', 'ip.id_interval_perawatan', '=', 'p.id_interval_perawatan')
            ->whereNull('p.dihapus_pada')
            ->whereIn('p.id_armada', $armadaIds)
            ->where('p.status', 'selesai')
            ->whereRaw('p.id_perawatan = (
                SELECT p2.id_perawatan FROM perawatan_armada p2
                WHERE p2.id_armada = p.id_armada
                  AND p2.status = \'selesai\'
                  AND p2.dihapus_pada IS NULL
                ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                LIMIT 1
            )')
            ->get(['p.id_armada', 'p.tanggal', 'ip.interval_km', 'ip.interval_bulan'])
            ->map(fn ($r) => (object) [
                'id_armada' => $r->id_armada,
                'tanggal'   => $r->tanggal,
                'label'     => IntervalLabelBuilder::buildOrFallback(
                    $r->interval_km !== null ? (int) $r->interval_km : null,
                    $r->interval_bulan !== null ? (int) $r->interval_bulan : null,
                ),
            ])
            ->all();
    }

    public function pembelianUntukPerawatan(string $idPerawatan): array
    {
        return DB::table('pembelian_sparepart as p')
            ->leftJoin('supplier as s', 's.id_supplier', '=', 'p.id_supplier')
            ->where('p.id_perawatan', $idPerawatan)
            ->whereNull('p.dihapus_pada')
            ->orderBy('p.tanggal_pengajuan', 'desc')
            ->orderBy('p.dibuat_pada', 'desc')
            ->get([
                'p.id_pembelian', 'p.nomor_pengajuan', 'p.status', 'p.total_estimasi', 'p.total_aktual',
                'p.tanggal_pengajuan', 'p.tanggal_pembelian', 's.nama as nama_supplier',
            ])
            ->all();
    }

    public function permintaanPembelianUntukPerawatan(string $idPerawatan): array
    {
        return DB::table('permintaan_pembelian')
            ->where('id_perawatan', $idPerawatan)
            ->whereNull('dihapus_pada')
            ->where('status', '!=', 'dibatalkan')
            ->orderBy('tanggal_permintaan', 'desc')
            ->orderBy('dibuat_pada', 'desc')
            ->get([
                'id_permintaan', 'nomor_permintaan', 'status', 'tipe',
                'total_estimasi', 'total_aktual', 'tanggal_permintaan',
            ])
            ->all();
    }

    public function findArmadaPapanUnit(string $idPerusahaan, ?string $search = null): array
    {
        return DB::table('armada')
            ->leftJoin('jenis_kendaraan', function ($join) {
                $join->on('jenis_kendaraan.id_jenis_kendaraan', '=', 'armada.id_jenis_kendaraan')
                    ->whereNull('jenis_kendaraan.dihapus_pada');
            })
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('armada.dihapus_pada')
            ->where('armada.status', '!=', 'tidak_aktif')
            ->when($search, fn ($q, $v) => $q->where('armada.nopol', 'like', "%{$v}%"))
            ->orderBy('armada.nopol')
            ->select(
                'armada.id_armada',
                'armada.nopol',
                'armada.merk',
                'armada.status as status_armada',
                'armada.id_jenis_kendaraan',
                'armada.tanggal_beli',
                'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan',
            )
            ->get()
            ->all();
    }

    public function intervalUntukBanyak(array $idIntervalList): array
    {
        $idIntervalList = array_values(array_unique(array_filter($idIntervalList)));
        if ($idIntervalList === []) {
            return [];
        }

        return DB::table('interval_perawatan')
            ->whereIn('id_interval_perawatan', $idIntervalList)
            ->get(['id_interval_perawatan', 'interval_km', 'interval_bulan'])
            ->keyBy('id_interval_perawatan')
            ->all();
    }
}
