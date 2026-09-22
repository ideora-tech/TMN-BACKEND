<?php

declare(strict_types=1);

namespace App\Modules\KetersediaanVendor;

use App\Modules\KetersediaanVendor\Contracts\KetersediaanVendorRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class KetersediaanVendorRepository implements KetersediaanVendorRepositoryInterface
{
    private const STATUS_TERPAKAI = ['pending', 'aktif'];

    private const KOLOM_UNIT = [
        'aset'   => ['p.id_armada', 'a.id_armada'],
        'vendor' => ['p.id_armada_vendor', 'av.id_armada_vendor'],
    ];

    private const STATUS_SQL = "CASE WHEN u.perawatan_manual > 0 OR u.perawatan_dalam_proses > 0 THEN 'perawatan' WHEN u.pakai_hari_ini > 0 OR u.trip_berjalan > 0 OR u.digunakan > 0 THEN 'dipakai' WHEN u.jadwal_berikutnya IS NOT NULL THEN 'terjadwal' ELSE 'tersedia' END";

    public function listUnit(string $idPerusahaan, string $hariIni): array
    {
        return array_merge(
            $this->queryUnit('aset', $idPerusahaan, $hariIni)->get()->all(),
            $this->queryUnit('vendor', $idPerusahaan, $hariIni)->get()->all(),
        );
    }

    public function findUnit(string $sumber, string $idUnit, string $idPerusahaan, string $hariIni): ?object
    {
        return $this->queryUnit($sumber, $idPerusahaan, $hariIni, $idUnit)->first();
    }

    public function riwayatProyek(string $sumber, string $idUnit, string $idPerusahaan, string $hariIni): array
    {
        $sudahDipakai = "p.tanggal_tugas <= ? AND p.status != 'batal'";

        return DB::table('penugasan as p')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->leftJoin('klien as k', 'k.id_klien', '=', 'pr.id_klien')
            ->where(self::KOLOM_UNIT[$sumber][0], $idUnit)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->groupBy('pr.id_proyek', 'pr.kode_proyek', 'pr.nama_proyek', 'pr.status', 'k.nama_klien')
            ->selectRaw(
                "pr.id_proyek, pr.kode_proyek, pr.nama_proyek, pr.status as status_proyek, k.nama_klien,
                COUNT(DISTINCT CASE WHEN {$sudahDipakai} THEN p.tanggal_tugas END) as hari_pakai,
                COUNT(DISTINCT CASE WHEN p.tanggal_tugas > ? AND p.status IN ('pending', 'aktif') THEN p.tanggal_tugas END) as hari_terjadwal,
                MIN(CASE WHEN {$sudahDipakai} THEN p.tanggal_tugas END) as pertama_dipakai,
                MAX(CASE WHEN {$sudahDipakai} THEN p.tanggal_tugas END) as terakhir_dipakai",
                [$hariIni, $hariIni, $hariIni, $hariIni]
            )
            ->get()
            ->all();
    }

    private function subPenugasan(string $idPerusahaan, string $sumber): Builder
    {
        [$kolomPenugasan, $kolomUnit] = self::KOLOM_UNIT[$sumber];

        return DB::table('penugasan as p')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->whereColumn($kolomPenugasan, $kolomUnit)
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->where('pr.id_perusahaan', $idPerusahaan);
    }

    private function basisAset(string $idPerusahaan, string $hariIni, ?string $idUnit): Builder
    {
        $dokumen = fn (string $jenis): Builder => DB::table('dokumen_armada as d')
            ->whereColumn('d.id_armada', 'a.id_armada')
            ->where('d.jenis_dokumen', $jenis)
            ->where('d.aktif', 1)
            ->whereNull('d.dihapus_pada')
            ->selectRaw('MAX(d.berlaku_sampai)');

        $perawatan = fn (string $status): Builder => DB::table('perawatan_armada as pa')
            ->whereColumn('pa.id_armada', 'a.id_armada')
            ->whereNull('pa.dihapus_pada')
            ->where('pa.status', $status);

        return DB::table('armada as a')
            ->leftJoin('jenis_kendaraan as jkd', 'jkd.id_jenis_kendaraan', '=', 'a.id_jenis_kendaraan')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->where('a.kepemilikan', 'internal')
            ->where('a.aktif', 1)
            ->where('a.status', '!=', 'tidak_aktif')
            ->whereNull('a.dihapus_pada')
            ->when($idUnit !== null, fn (Builder $q) => $q->where('a.id_armada', $idUnit))
            ->select([
                'a.id_armada as id_unit', 'a.nopol', 'a.merk', 'a.model', 'a.id_jenis_kendaraan',
                'a.kapasitas_muatan_kg', 'a.tahun', 'jkd.nama_jenis as nama_jenis_kendaraan',
            ])
            ->selectRaw("NULL as id_vendor, NULL as jenis, NULL as kapasitas, NULL as nama_vendor, NULL as telepon_vendor, NULL as pic_vendor")
            ->selectRaw("CASE WHEN a.status = 'perawatan' THEN 1 ELSE 0 END as perawatan_manual")
            ->selectRaw("CASE WHEN a.status = 'digunakan' THEN 1 ELSE 0 END as digunakan")
            ->selectSub($dokumen('STNK'), 'masa_berlaku_stnk')
            ->selectSub($dokumen('KIR'), 'masa_berlaku_kir')
            ->selectSub($perawatan('dalam_proses')->selectRaw('COUNT(*)'), 'perawatan_dalam_proses')
            ->selectSub($perawatan('terjadwal')->where('pa.tanggal', '>=', $hariIni)->selectRaw('MIN(pa.tanggal)'), 'perawatan_berikutnya');
    }

    private function basisVendor(string $idPerusahaan, ?string $idUnit): Builder
    {
        return DB::table('armada_vendor as av')
            ->join('vendor as v', 'v.id_vendor', '=', 'av.id_vendor')
            ->leftJoin('jenis_kendaraan as jkd', 'jkd.id_jenis_kendaraan', '=', 'av.id_jenis_kendaraan')
            ->where('v.id_perusahaan', $idPerusahaan)
            ->where('v.aktif', 1)
            ->whereNull('v.dihapus_pada')
            ->whereNull('av.dihapus_pada')
            ->where('av.aktif', 1)
            ->when($idUnit !== null, fn (Builder $q) => $q->where('av.id_armada_vendor', $idUnit))
            ->select([
                'av.id_armada_vendor as id_unit', 'av.id_vendor', 'av.nopol', 'av.merk', 'av.jenis', 'av.id_jenis_kendaraan',
                'av.kapasitas', 'av.tahun', 'av.masa_berlaku_stnk', 'av.masa_berlaku_kir',
                'v.nama_vendor', 'v.telepon as telepon_vendor', 'v.pic_nama as pic_vendor',
                'jkd.nama_jenis as nama_jenis_kendaraan',
            ])
            ->selectRaw('NULL as model, NULL as kapasitas_muatan_kg, NULL as perawatan_berikutnya')
            ->selectRaw('0 as perawatan_manual, 0 as perawatan_dalam_proses, 0 as digunakan');
    }

    private function queryUnit(string $sumber, string $idPerusahaan, string $hariIni, ?string $idUnit = null): Builder
    {
        if (!isset(self::KOLOM_UNIT[$sumber])) {
            throw new \InvalidArgumentException('Sumber unit tidak dikenal');
        }

        [$kolomPenugasan, $kolomUnit] = self::KOLOM_UNIT[$sumber];

        $riwayat = fn (): Builder => $this->subPenugasan($idPerusahaan, $sumber)
            ->where('p.status', '!=', 'batal')
            ->where('p.tanggal_tugas', '<=', $hariIni);

        $terpakai = fn (): Builder => $this->subPenugasan($idPerusahaan, $sumber)
            ->whereIn('p.status', self::STATUS_TERPAKAI);

        $tripBerjalan = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jkb', 'jkb.id_jadwal', '=', 't.id_jadwal')
            ->join('penugasan as p', 'p.id_penugasan', '=', 'jkb.id_penugasan')
            ->whereColumn($kolomPenugasan, $kolomUnit)
            ->where('t.status', 'berjalan')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jkb.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->selectRaw('COUNT(*)');

        $basis = ($sumber === 'aset' ? $this->basisAset($idPerusahaan, $hariIni, $idUnit) : $this->basisVendor($idPerusahaan, $idUnit))
            ->selectSub($riwayat()->selectRaw('COUNT(DISTINCT p.tanggal_tugas)'), 'hari_pakai')
            ->selectSub($riwayat()->selectRaw('COUNT(DISTINCT p.id_proyek)'), 'jumlah_proyek')
            ->selectSub($riwayat()->selectRaw('MAX(p.tanggal_tugas)'), 'terakhir_dipakai')
            ->selectSub($riwayat()->select('p.id_proyek')->orderByDesc('p.tanggal_tugas')->orderByDesc('p.dibuat_pada')->limit(1), 'id_proyek_terakhir')
            ->selectSub($terpakai()->where('p.tanggal_tugas', $hariIni)->selectRaw('COUNT(*)'), 'pakai_hari_ini')
            ->selectSub($tripBerjalan, 'trip_berjalan')
            ->selectSub($terpakai()->where('p.tanggal_tugas', '>', $hariIni)->selectRaw('MIN(p.tanggal_tugas)'), 'jadwal_berikutnya')
            ->selectSub($terpakai()->where('p.tanggal_tugas', '>=', $hariIni)->selectRaw('MAX(p.tanggal_tugas)'), 'terjadwal_sampai')
            ->selectSub($terpakai()->where('p.tanggal_tugas', '>=', $hariIni)->select('p.id_proyek')->orderBy('p.tanggal_tugas')->orderBy('p.dibuat_pada')->limit(1), 'id_proyek_jadwal');

        return DB::query()
            ->fromSub($basis, 'u')
            ->leftJoin('proyek as pt', 'pt.id_proyek', '=', 'u.id_proyek_terakhir')
            ->leftJoin('klien as kt', 'kt.id_klien', '=', 'pt.id_klien')
            ->leftJoin('proyek as pj', 'pj.id_proyek', '=', 'u.id_proyek_jadwal')
            ->leftJoin('klien as kj', 'kj.id_klien', '=', 'pj.id_klien')
            ->when($sumber === 'vendor', fn (Builder $q) => $q->where('u.jumlah_proyek', '>', 0))
            ->selectRaw(
                "u.*, '{$sumber}' as sumber,
                pt.kode_proyek as kode_proyek_terakhir, pt.nama_proyek as nama_proyek_terakhir, kt.nama_klien as nama_klien_terakhir,
                pj.kode_proyek as kode_proyek_jadwal, pj.nama_proyek as nama_proyek_jadwal, kj.nama_klien as nama_klien_jadwal, "
                . self::STATUS_SQL . ' as status_ketersediaan'
            );
    }
}
