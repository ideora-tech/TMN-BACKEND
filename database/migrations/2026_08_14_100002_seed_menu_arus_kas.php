<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenuArusKas = 'm0000001-0000-4000-8000-000000000087';
    private string $idKeuangan    = 'm0000001-0000-4000-8000-000000000030';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuArusKas, 'nama_menu' => 'Arus Kas', 'path' => '/arus-kas',
                'icon' => 'wallet', 'id_menu_induk' => $this->idKeuangan, 'urutan' => 5,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuArusKas, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuArusKas, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuArusKas, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenuArusKas, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idMenuArusKas, 'kode_peran' => 'SUPERADMIN'],
        ]);

        $izin = [
            ['ADMIN', 'lihat', 1],
            ['ADMIN', 'tambah', 1],
            ['ADMIN', 'ubah', 1],
            ['ADMIN', 'hapus', 1],
            ['MANAGER', 'lihat', 1],
            ['MANAGER', 'tambah', 1],
            ['MANAGER', 'ubah', 1],
            ['MANAGER', 'hapus', 1],
            ['KEUANGAN', 'lihat', 1],
            ['KEUANGAN', 'tambah', 1],
            ['KEUANGAN', 'ubah', 1],
            ['KEUANGAN', 'hapus', 1],
            ['DISPATCHER', 'lihat', 1],
            ['DISPATCHER', 'tambah', 1],
            ['DISPATCHER', 'ubah', 0],
            ['DISPATCHER', 'hapus', 0],
        ];

        foreach ($izin as [$kodePeran, $aksi, $diizinkan]) {
            $exists = DB::table('izin_peran')
                ->where('id_menu', $this->idMenuArusKas)
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
                'id_menu'       => $this->idMenuArusKas,
                'aksi'          => $aksi,
                'diizinkan'     => $diizinkan,
                'dibuat_pada'   => $now,
            ]);
        }
    }

    public function down(): void
    {
    }
};
