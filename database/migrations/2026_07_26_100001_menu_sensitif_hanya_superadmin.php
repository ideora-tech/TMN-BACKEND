<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PATH_SENSITIF = ['/pengguna', '/peran', '/perusahaan', '/menu-admin'];
    private string $idMenuJadwal = 'm0000004-0000-4000-8000-000000000001';

    public function up(): void
    {
        $idMenuSensitif = DB::table('menu')->whereIn('path', self::PATH_SENSITIF)->pluck('id_menu');
        DB::table('menu_peran')
            ->whereIn('id_menu', $idMenuSensitif)
            ->where('kode_peran', '!=', 'SUPERADMIN')
            ->delete();

        // Baris menu tersembunyi utk sasaran izin:jadwal — middleware CheckIzinPeran
        // mencocokkan izin lewat menu.path, tapi '/jadwal' tidak pernah punya baris
        // menu (bukan halaman sidebar). aktif=0 agar tidak tampil di menu tree.
        DB::table('menu')->updateOrInsert(
            ['path' => '/jadwal'],
            [
                'id_menu'       => $this->idMenuJadwal,
                'nama_menu'     => 'Jadwal Keberangkatan (API)',
                'path'          => '/jadwal',
                'icon'          => null,
                'id_menu_induk' => null,
                'urutan'        => 99,
                'aktif'         => 0,
                'dibuat_pada'   => now(),
            ]
        );
    }

    public function down(): void
    {
        // menu_peran yang dihapus tidak di-restore otomatis (butuh tahu role apa
        // saja yang tadinya punya akses) — sama seperti pola hapus_menu_tarif_rute.
        DB::table('menu')->where('id_menu', $this->idMenuJadwal)->delete();
    }
};
