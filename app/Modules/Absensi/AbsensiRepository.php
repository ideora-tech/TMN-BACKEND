<?php

declare(strict_types=1);

namespace App\Modules\Absensi;

use App\Modules\Absensi\Contracts\AbsensiRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class AbsensiRepository implements AbsensiRepositoryInterface
{
    public function findByTanggal(string $idPerusahaan, string $tanggal): array
    {
        return DB::table('absensi')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereDate('tanggal', $tanggal)
            ->get()
            ->all();
    }

    public function findByKaryawanTanggal(string $idKaryawan, string $tanggal): ?object
    {
        return DB::table('absensi')
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->whereDate('tanggal', $tanggal)
            ->first();
    }

    public function upsert(string $idPerusahaan, string $idKaryawan, string $tanggal, array $data): void
    {
        $existing = DB::table('absensi')
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->whereDate('tanggal', $tanggal)
            ->first();

        if ($existing !== null) {
            DB::table('absensi')
                ->where('id_absensi', $existing->id_absensi)
                ->update(RecordHelper::stampUpdate($data));
            return;
        }

        DB::table('absensi')->insert(RecordHelper::stampCreate(array_merge($data, [
            'id_perusahaan' => $idPerusahaan,
            'id_karyawan'   => $idKaryawan,
            'tanggal'       => $tanggal,
        ]), 'id_absensi'));
    }

    public function hapusByKaryawanTanggal(string $idKaryawan, string $tanggal): void
    {
        DB::table('absensi')
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->whereDate('tanggal', $tanggal)
            ->update(RecordHelper::stampDelete());
    }

    public function rekapBulanan(string $idPerusahaan, string $awal, string $akhir): array
    {
        return DB::table('absensi')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereBetween('tanggal', [$awal, $akhir])
            ->groupBy('id_karyawan', 'status')
            ->selectRaw('id_karyawan, status, COUNT(*) as jumlah')
            ->get()
            ->all();
    }

    public function jamPulangDalamRentang(string $idPerusahaan, string $awal, string $akhir): array
    {
        return DB::table('absensi')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereBetween('tanggal', [$awal, $akhir])
            ->whereNotNull('jam_pulang')
            ->where('pulang_mandiri', 0)
            ->select('id_karyawan', 'jam_pulang')
            ->get()
            ->all();
    }

    public function getPengaturan(string $idPerusahaan): ?object
    {
        return DB::table('pengaturan_absensi')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function upsertPengaturan(string $idPerusahaan, array $data): object
    {
        $existing = $this->getPengaturan($idPerusahaan);

        if ($existing !== null) {
            DB::table('pengaturan_absensi')
                ->where('id_pengaturan', $existing->id_pengaturan)
                ->update(RecordHelper::stampUpdate($data));
        } else {
            DB::table('pengaturan_absensi')->insert(RecordHelper::stampCreate(
                array_merge($data, ['id_perusahaan' => $idPerusahaan]),
                'id_pengaturan'
            ));
        }

        return $this->getPengaturan($idPerusahaan);
    }

    public function cutiDisetujuiDalamRentang(string $idPerusahaan, string $awal, string $akhir): array
    {
        return DB::table('pengajuan_cuti')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNotNull('id_karyawan')
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $akhir)
            ->whereDate('tanggal_selesai', '>=', $awal)
            ->select('id_karyawan', 'tanggal_mulai', 'tanggal_selesai')
            ->get()
            ->all();
    }
}
