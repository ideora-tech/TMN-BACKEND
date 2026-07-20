<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Dashboard\Contracts\DashboardRepositoryInterface;
use App\Modules\Faktur\FakturModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardRepository implements DashboardRepositoryInterface
{
    public function stats(string $idPerusahaan): array
    {
        $bulanIni = now()->startOfMonth();

        return [
            'tripBerjalan' => TripModel::active()
                ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
                ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
                ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
                ->where('pr.id_perusahaan', $idPerusahaan)
                ->where('trip.status', 'berjalan')
                ->whereNull('jk.dihapus_pada')
                ->whereNull('p.dihapus_pada')
                ->whereNull('pr.dihapus_pada')
                ->count(),
            'armadaTersedia' => ArmadaModel::active()
                ->where('id_perusahaan', $idPerusahaan)
                ->where('status', 'tersedia')
                ->count(),
            'armadaBeroperasi' => ArmadaModel::active()
                ->where('id_perusahaan', $idPerusahaan)
                ->where('status', 'digunakan')
                ->count(),
            'proyekBerjalan' => ProyekModel::active()
                ->where('id_perusahaan', $idPerusahaan)
                ->where('status', 'aktif')
                ->count(),
            'fakturDraft' => FakturModel::active()
                ->where('id_perusahaan', $idPerusahaan)
                ->where('status', 'draft')
                ->count(),
            'pendapatanBulanIni' => (int) FakturModel::active()
                ->where('id_perusahaan', $idPerusahaan)
                ->where('status', 'lunas')
                ->where('dibuat_pada', '>=', $bulanIni)
                ->sum('total'),
            'piutangBeredar' => FakturModel::active()
                ->where('id_perusahaan', $idPerusahaan)
                ->where('status', 'terkirim')
                ->count(),
        ];
    }

    public function dokumenExpiring(string $idPerusahaan, int $days = 30): Collection
    {
        $today = now()->toDateString();
        $batas = now()->addDays($days)->toDateString();

        $dokArmada = DB::table('dokumen_armada as d')
            ->join('armada as a', 'd.id_armada', '=', 'a.id_armada')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->whereNull('d.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->whereNotNull('d.berlaku_sampai')
            ->whereBetween('d.berlaku_sampai', [$today, $batas])
            ->select('d.jenis_dokumen', 'a.nopol as pemilik', 'd.berlaku_sampai', DB::raw("'armada' as tipe"));

        $dokVendor = DB::table('dokumen_vendor as d')
            ->join('vendor as v', 'd.id_vendor', '=', 'v.id_vendor')
            ->where('v.id_perusahaan', $idPerusahaan)
            ->whereNull('d.dihapus_pada')
            ->whereNull('v.dihapus_pada')
            ->whereNotNull('d.berlaku_sampai')
            ->whereBetween('d.berlaku_sampai', [$today, $batas])
            ->select('d.jenis_dokumen', 'v.nama_vendor as pemilik', 'd.berlaku_sampai', DB::raw("'vendor' as tipe"));

        return $dokArmada->unionAll($dokVendor)->get();
    }

    public function tripTerlambat(string $idPerusahaan, int $jamBatas = 24): Collection
    {
        $cutoff = now()->subHours($jamBatas)->toDateTimeString();

        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->where('t.status', 'berjalan')
            ->whereNotNull('t.waktu_checkin')
            ->where('t.waktu_checkin', '<', $cutoff)
            ->whereNull('t.waktu_checkout')
            ->select('t.id_trip', 'pr.nama_proyek', 't.waktu_checkin')
            ->get();
    }

    public function servisJatuhTempo(string $idPerusahaan, int $days = 30): Collection
    {
        $batas = now()->addDays($days)->toDateString();

        return DB::table('perawatan_armada as p1')
            ->join('armada as a', 'a.id_armada', '=', 'p1.id_armada')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->whereNull('p1.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->whereNotNull('p1.jadwal_servis_berikutnya')
            ->where('p1.jadwal_servis_berikutnya', '<=', $batas)
            ->whereRaw('p1.id_perawatan = (
                SELECT p2.id_perawatan FROM perawatan_armada p2
                WHERE p2.id_armada = p1.id_armada AND p2.dihapus_pada IS NULL
                ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                LIMIT 1
            )')
            ->select('a.id_armada', 'a.nopol', 'p1.jenis_perawatan', 'p1.jadwal_servis_berikutnya')
            ->get();
    }
}
