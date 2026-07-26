<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idHr       = 'm0000001-0000-4000-8000-000000000060';
    private string $idKaryawan = 'm0000001-0000-4000-8000-000000000061';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idHr, 'nama_menu' => 'HR', 'path' => null,
                'icon' => 'users', 'id_menu_induk' => null, 'urutan' => 9,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
            [
                'id_menu' => $this->idKaryawan, 'nama_menu' => 'Karyawan', 'path' => '/karyawan',
                'icon' => 'userCircle', 'id_menu_induk' => $this->idHr, 'urutan' => 1,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idHr, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idHr, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idHr, 'kode_peran' => 'SUPERADMIN'],
            ['id_menu' => $this->idKaryawan, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idKaryawan, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idKaryawan, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        // Jangan hapus baris yang mungkin berasal dari MenuSeeder — cukup biarkan.
    }
};
