<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idPenawaran = 'm0000001-0000-4000-8000-000000000013';
    private string $idRute      = 'm0000001-0000-4000-8000-000000000027';
    private string $idSales     = 'm0000001-0000-4000-8000-000000000010';
    private string $idOps       = 'm0000001-0000-4000-8000-000000000020';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu'       => $this->idPenawaran,
                'nama_menu'     => 'Penawaran',
                'path'          => '/penawaran',
                'icon'          => 'notepad',
                'id_menu_induk' => $this->idSales,
                'urutan'        => 3,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
            [
                'id_menu'       => $this->idRute,
                'nama_menu'     => 'Rute',
                'path'          => '/rute',
                'icon'          => 'path',
                'id_menu_induk' => $this->idOps,
                'urutan'        => 7,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            // Penawaran — Sales, Manager, Admin, Superadmin
            ['id_menu' => $this->idPenawaran, 'kode_peran' => 'SALES'],
            ['id_menu' => $this->idPenawaran, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idPenawaran, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idPenawaran, 'kode_peran' => 'SUPERADMIN'],
            // Rute — Dispatcher, Manager, Admin, Superadmin
            ['id_menu' => $this->idRute, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idRute, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idRute, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idRute, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->whereIn('id_menu', [$this->idPenawaran, $this->idRute])->delete();
        DB::table('menu')->whereIn('id_menu', [$this->idPenawaran, $this->idRute])->delete();
    }
};
