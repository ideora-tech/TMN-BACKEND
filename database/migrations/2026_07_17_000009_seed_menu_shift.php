<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenuShift  = 'm0000001-0000-4000-8000-000000000057';
    private string $idDataMaster = 'm0000001-0000-4000-8000-000000000050';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuShift, 'nama_menu' => 'Shift', 'path' => '/shift',
                'icon' => 'calendar', 'id_menu_induk' => $this->idDataMaster, 'urutan' => 7,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuShift, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idMenuShift, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuShift, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuShift, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idMenuShift)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuShift)->delete();
    }
};
