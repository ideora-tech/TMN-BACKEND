<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenuAdmin  = 'm0000001-0000-4000-8000-000000000046';
    private string $idPengaturan = 'm0000001-0000-4000-8000-000000000040';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu'       => $this->idMenuAdmin,
                'nama_menu'     => 'Menu',
                'path'          => '/menu-admin',
                'icon'          => 'treeStructure',
                'id_menu_induk' => $this->idPengaturan,
                'urutan'        => 5,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuAdmin, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuAdmin, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idMenuAdmin)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuAdmin)->delete();
    }
};
