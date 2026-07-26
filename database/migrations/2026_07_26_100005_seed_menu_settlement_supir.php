<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenuSettlement = 'm0000001-0000-4000-8000-000000000033';
    private string $idKeuangan       = 'm0000001-0000-4000-8000-000000000030';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuSettlement, 'nama_menu' => 'Settlement Supir', 'path' => '/settlement-supir',
                'icon' => 'receipt', 'id_menu_induk' => $this->idKeuangan, 'urutan' => 3,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuSettlement, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenuSettlement, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuSettlement, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuSettlement, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idMenuSettlement)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuSettlement)->delete();
    }
};
