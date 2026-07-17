<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idTarifRute    = 'm0000002-0000-4000-8000-000000000001';
    private string $idParameterBok = 'm0000002-0000-4000-8000-000000000002';
    private string $idSales        = 'm0000001-0000-4000-8000-000000000010';
    private string $idOps          = 'm0000001-0000-4000-8000-000000000020';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu'       => $this->idTarifRute,
                'nama_menu'     => 'Tarif Rute',
                'path'          => '/tarif-rute',
                'icon'          => 'fileText',
                'id_menu_induk' => $this->idSales,
                'urutan'        => 4,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
            [
                'id_menu'       => $this->idParameterBok,
                'nama_menu'     => 'Parameter BOK',
                'path'          => '/parameter-bok',
                'icon'          => 'wrench',
                'id_menu_induk' => $this->idOps,
                'urutan'        => 20,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            // Tarif Rute — Sales, Manager, Admin, Superadmin
            ['id_menu' => $this->idTarifRute, 'kode_peran' => 'SALES'],
            ['id_menu' => $this->idTarifRute, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idTarifRute, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idTarifRute, 'kode_peran' => 'SUPERADMIN'],
            // Parameter BOK — Manager, Admin, Superadmin
            ['id_menu' => $this->idParameterBok, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idParameterBok, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idParameterBok, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->whereIn('id_menu', [$this->idTarifRute, $this->idParameterBok])->delete();
        DB::table('menu')->whereIn('id_menu', [$this->idTarifRute, $this->idParameterBok])->delete();
    }
};
