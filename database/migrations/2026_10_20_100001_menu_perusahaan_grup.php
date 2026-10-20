<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenu       = 'c4e8a2d6-7f1b-4a3c-9e5d-2b6f8a1c4e70';
    private string $idDataMaster = 'm0000001-0000-4000-8000-000000000050';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Perusahaan Grup', 'path' => '/perusahaan-grup',
                'icon' => 'office', 'id_menu_induk' => $this->idDataMaster, 'urutan' => 12,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenu, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'SUPERADMIN'],
        ]);

        $izin = [
            ['ADMIN', 'lihat', 1],
            ['ADMIN', 'tambah', 1],
            ['ADMIN', 'ubah', 1],
            ['ADMIN', 'hapus', 1],
            ['MANAGER', 'lihat', 1],
            ['MANAGER', 'tambah', 0],
            ['MANAGER', 'ubah', 0],
            ['MANAGER', 'hapus', 0],
        ];

        foreach ($izin as [$kodePeran, $aksi, $diizinkan]) {
            $exists = DB::table('izin_peran')
                ->where('id_menu', $this->idMenu)
                ->where('kode_peran', $kodePeran)
                ->where('aksi', $aksi)
                ->whereNull('id_perusahaan')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('izin_peran')->insert([
                'id_izin'       => (string) Str::uuid(),
                'id_perusahaan' => null,
                'kode_peran'    => $kodePeran,
                'id_menu'       => $this->idMenu,
                'aksi'          => $aksi,
                'diizinkan'     => $diizinkan,
                'dibuat_pada'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu')->where('id_menu', $this->idMenu)->delete();
    }
};
