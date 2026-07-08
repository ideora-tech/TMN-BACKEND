<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idLogError   = 'm0000001-0000-4000-8000-000000000043';
    private string $idPengaturan = 'm0000001-0000-4000-8000-000000000040';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu'       => $this->idLogError,
                'nama_menu'     => 'Log Error',
                'path'          => '/log-error',
                'icon'          => 'bug',
                'id_menu_induk' => $this->idPengaturan,
                'urutan'        => 3,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idLogError, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idLogError, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idLogError)->delete();
        DB::table('menu')->where('id_menu', $this->idLogError)->delete();
    }
};
