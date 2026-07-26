<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenuAbsensi = 'm0000001-0000-4000-8000-000000000063';
    private string $idHr          = 'm0000001-0000-4000-8000-000000000060';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuAbsensi, 'nama_menu' => 'Absensi', 'path' => '/absensi',
                'icon' => 'calendar', 'id_menu_induk' => $this->idHr, 'urutan' => 3,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuAbsensi, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuAbsensi, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuAbsensi, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idMenuAbsensi)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuAbsensi)->delete();
    }
};
