<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * id_absensi hasil salinan sengaja sama dengan id di absensi_supir supaya
     * migration idempoten dan down() hanya menghapus baris hasil salinan.
     */
    public function up(): void
    {
        $daftar = DB::table('absensi_supir as a')
            ->join('supir as s', 's.id_supir', '=', 'a.id_supir')
            ->whereNull('a.dihapus_pada')
            ->whereNotNull('s.id_karyawan')
            ->select('a.id_absensi', 'a.id_perusahaan', 'a.tanggal', 'a.status', 'a.keterangan', 'a.dibuat_pada', 's.id_karyawan')
            ->orderBy('a.tanggal')
            ->get();

        foreach ($daftar as $r) {
            $sudahAda = DB::table('absensi')
                ->where(fn ($q) => $q->where('id_absensi', $r->id_absensi)
                    ->orWhere(fn ($q2) => $q2->whereNull('dihapus_pada')
                        ->where('id_karyawan', $r->id_karyawan)
                        ->whereDate('tanggal', $r->tanggal)))
                ->exists();
            if ($sudahAda) {
                continue;
            }

            $sedangCuti = DB::table('pengajuan_cuti')
                ->whereNull('dihapus_pada')
                ->where('id_karyawan', $r->id_karyawan)
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', $r->tanggal)
                ->whereDate('tanggal_selesai', '>=', $r->tanggal)
                ->exists();
            if ($sedangCuti) {
                continue;
            }

            $hadir = $r->status === 'hadir';
            DB::table('absensi')->insert([
                'id_absensi'    => $r->id_absensi,
                'id_perusahaan' => $r->id_perusahaan,
                'id_karyawan'   => $r->id_karyawan,
                'tanggal'       => $r->tanggal,
                'status'        => $hadir ? 'hadir' : 'izin',
                'jam_masuk'     => $hadir && $r->dibuat_pada !== null ? date('H:i:s', strtotime((string) $r->dibuat_pada)) : null,
                'keterangan'    => $hadir ? $r->keterangan : ($r->keterangan ?? 'Berhalangan (absen dari aplikasi supir)'),
                'dibuat_pada'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('absensi')
            ->whereIn('id_absensi', DB::table('absensi_supir')->select('id_absensi'))
            ->delete();
    }
};
