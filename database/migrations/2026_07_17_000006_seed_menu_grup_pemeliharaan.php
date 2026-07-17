<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idGrup            = 'm0000001-0000-4000-8000-000000000080';
    private string $idJenisPerawatan  = 'm0000001-0000-4000-8000-000000000081';
    private string $idSparepart       = 'm0000001-0000-4000-8000-000000000082';
    private string $idPerawatanArmada = 'm0000001-0000-4000-8000-000000000028';
    private string $idDokumenArmada   = 'm0000001-0000-4000-8000-000000000029';
    private string $idOperasional     = 'm0000001-0000-4000-8000-000000000020';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idGrup, 'nama_menu' => 'Pemeliharaan', 'path' => null,
                'icon' => 'wrench', 'id_menu_induk' => null, 'urutan' => 5,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
            [
                'id_menu' => $this->idJenisPerawatan, 'nama_menu' => 'Jenis Perawatan', 'path' => '/jenis-perawatan',
                'icon' => 'clipboard', 'id_menu_induk' => $this->idGrup, 'urutan' => 3,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
            [
                'id_menu' => $this->idSparepart, 'nama_menu' => 'Spare Part', 'path' => '/sparepart',
                'icon' => 'puzzle', 'id_menu_induk' => $this->idGrup, 'urutan' => 4,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        // pindahkan 2 menu existing ke grup baru
        DB::table('menu')->where('id_menu', $this->idPerawatanArmada)
            ->update(['id_menu_induk' => $this->idGrup, 'urutan' => 1]);
        DB::table('menu')->where('id_menu', $this->idDokumenArmada)
            ->update(['id_menu_induk' => $this->idGrup, 'urutan' => 2]);

        $menuPeran = [];
        foreach ([$this->idGrup, $this->idJenisPerawatan, $this->idSparepart] as $idMenu) {
            foreach (['DISPATCHER', 'MANAGER', 'ADMIN', 'SUPERADMIN'] as $peran) {
                $menuPeran[] = ['id_menu' => $idMenu, 'kode_peran' => $peran];
            }
        }
        DB::table('menu_peran')->insertOrIgnore($menuPeran);
    }

    public function down(): void
    {
        DB::table('menu')->where('id_menu', $this->idPerawatanArmada)
            ->update(['id_menu_induk' => $this->idOperasional, 'urutan' => 8]);
        DB::table('menu')->where('id_menu', $this->idDokumenArmada)
            ->update(['id_menu_induk' => $this->idOperasional, 'urutan' => 9]);

        DB::table('menu_peran')->whereIn('id_menu', [$this->idGrup, $this->idJenisPerawatan, $this->idSparepart])->delete();
        DB::table('menu')->whereIn('id_menu', [$this->idGrup, $this->idJenisPerawatan, $this->idSparepart])->delete();
    }
};
