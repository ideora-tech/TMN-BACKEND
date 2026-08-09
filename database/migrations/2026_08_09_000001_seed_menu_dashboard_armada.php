<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenuDashboardArmada = 'm0000005-0000-4000-8000-000000000001';
    private string $idOperasional = 'm0000001-0000-4000-8000-000000000020';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuDashboardArmada, 'nama_menu' => 'Dashboard Armada',
                'path' => '/dashboard-armada', 'icon' => 'truck',
                'id_menu_induk' => $this->idOperasional, 'urutan' => 0,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuDashboardArmada, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idMenuDashboardArmada, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuDashboardArmada, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuDashboardArmada, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idMenuDashboardArmada)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuDashboardArmada)->delete();
    }
};
