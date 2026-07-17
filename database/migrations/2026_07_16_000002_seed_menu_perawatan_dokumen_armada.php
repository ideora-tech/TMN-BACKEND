<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idPerawatanArmada = 'm0000001-0000-4000-8000-000000000028';
    private string $idDokumenArmada   = 'm0000001-0000-4000-8000-000000000029';
    private string $idOperasional     = 'm0000001-0000-4000-8000-000000000020';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu'       => $this->idPerawatanArmada,
                'nama_menu'     => 'Perawatan Armada',
                'path'          => '/perawatan-armada',
                'icon'          => 'wrench',
                'id_menu_induk' => $this->idOperasional,
                'urutan'        => 8,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
            [
                'id_menu'       => $this->idDokumenArmada,
                'nama_menu'     => 'Dokumen Armada',
                'path'          => '/dokumen-armada',
                'icon'          => 'fileText',
                'id_menu_induk' => $this->idOperasional,
                'urutan'        => 9,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idPerawatanArmada, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idPerawatanArmada, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idPerawatanArmada, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idPerawatanArmada, 'kode_peran' => 'SUPERADMIN'],
            ['id_menu' => $this->idDokumenArmada, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idDokumenArmada, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idDokumenArmada, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idDokumenArmada, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->whereIn('id_menu', [$this->idPerawatanArmada, $this->idDokumenArmada])->delete();
        DB::table('menu')->whereIn('id_menu', [$this->idPerawatanArmada, $this->idDokumenArmada])->delete();
    }
};
