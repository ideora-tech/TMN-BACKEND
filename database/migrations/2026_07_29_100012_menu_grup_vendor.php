<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idGrupVendor      = 'm0000001-0000-4000-8000-000000000070';
    private string $idDataVendor      = 'm0000001-0000-4000-8000-000000000023';
    private string $idArmadaVendor    = 'm0000001-0000-4000-8000-000000000071';
    private string $idSupirVendor     = 'm0000001-0000-4000-8000-000000000072';
    private string $idPenugasanVendor = 'm0000001-0000-4000-8000-000000000073';
    private string $idKontrakVendor   = 'm0000001-0000-4000-8000-000000000074';
    private string $idEvaluasiVendor  = 'm0000001-0000-4000-8000-000000000075';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idGrupVendor, 'nama_menu' => 'Vendor', 'path' => null,
                'icon' => 'building', 'id_menu_induk' => null, 'urutan' => 4, 'aktif' => 1,
                'dihapus_pada' => null, 'dihapus_oleh' => null, 'diubah_pada' => $now, 'dibuat_pada' => $now,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif', 'dihapus_pada', 'dihapus_oleh', 'diubah_pada']);

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idArmadaVendor, 'nama_menu' => 'Armada Vendor', 'path' => '/armada-vendor',
                'icon' => 'truck', 'id_menu_induk' => $this->idGrupVendor, 'urutan' => 3, 'aktif' => 1,
                'dihapus_pada' => null, 'dihapus_oleh' => null, 'dibuat_pada' => $now,
            ],
            [
                'id_menu' => $this->idSupirVendor, 'nama_menu' => 'Supir Vendor', 'path' => '/supir-vendor',
                'icon' => 'users', 'id_menu_induk' => $this->idGrupVendor, 'urutan' => 4, 'aktif' => 1,
                'dihapus_pada' => null, 'dihapus_oleh' => null, 'dibuat_pada' => $now,
            ],
            [
                'id_menu' => $this->idPenugasanVendor, 'nama_menu' => 'Penugasan Vendor', 'path' => '/penugasan-vendor',
                'icon' => 'clipboard', 'id_menu_induk' => $this->idGrupVendor, 'urutan' => 5, 'aktif' => 1,
                'dihapus_pada' => null, 'dihapus_oleh' => null, 'dibuat_pada' => $now,
            ],
        ], ['id_menu'], ['id_menu_induk', 'urutan', 'aktif', 'dihapus_pada', 'dihapus_oleh']);

        DB::table('menu')->where('id_menu', $this->idDataVendor)->update([
            'nama_menu'     => 'Data Vendor',
            'id_menu_induk' => $this->idGrupVendor,
            'urutan'        => 1,
        ]);

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idKontrakVendor, 'nama_menu' => 'Kontrak Vendor', 'path' => '/kontrak-vendor',
                'icon' => 'fileText', 'id_menu_induk' => $this->idGrupVendor, 'urutan' => 2, 'aktif' => 1,
                'dihapus_pada' => null, 'dihapus_oleh' => null, 'dibuat_pada' => $now,
            ],
            [
                'id_menu' => $this->idEvaluasiVendor, 'nama_menu' => 'Evaluasi Vendor', 'path' => '/evaluasi-vendor',
                'icon' => 'notepad', 'id_menu_induk' => $this->idGrupVendor, 'urutan' => 6, 'aktif' => 1,
                'dihapus_pada' => null, 'dihapus_oleh' => null, 'dibuat_pada' => $now,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif', 'dihapus_pada', 'dihapus_oleh']);

        $idMenuGrup = [
            $this->idGrupVendor,
            $this->idArmadaVendor,
            $this->idSupirVendor,
            $this->idPenugasanVendor,
            $this->idKontrakVendor,
            $this->idEvaluasiVendor,
        ];

        DB::table('menu_peran')->whereIn('id_menu', $idMenuGrup)->delete();

        $menuPeran = [];
        foreach ($idMenuGrup as $idMenu) {
            foreach (['DISPATCHER', 'MANAGER', 'ADMIN', 'SUPERADMIN'] as $peran) {
                $menuPeran[] = ['id_menu' => $idMenu, 'kode_peran' => $peran];
            }
        }
        DB::table('menu_peran')->insertOrIgnore($menuPeran);
    }

    public function down(): void
    {
        // Sengaja no-op: struktur menu bisa sudah diubah admin via UI setelah
        // migrasi ini jalan — restore manual via MenuSeeder bila diperlukan.
    }
};
