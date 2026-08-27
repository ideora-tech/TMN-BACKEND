<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenu = 'm0000001-0000-4000-8000-000000000094';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Persetujuan Saya', 'path' => '/persetujuan-saya',
                'icon' => 'userCheck', 'id_menu_induk' => null, 'urutan' => 10,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        $peran = ['SUPERADMIN', 'ADMIN', 'MANAGER', 'KEUANGAN', 'SALES', 'DISPATCHER'];
        DB::table('menu_peran')->insertOrIgnore(
            array_map(fn (string $p) => ['id_menu' => $this->idMenu, 'kode_peran' => $p], $peran)
        );

        foreach ($peran as $kodePeran) {
            foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
                $exists = DB::table('izin_peran')
                    ->where('id_menu', $this->idMenu)->where('kode_peran', $kodePeran)
                    ->where('aksi', $aksi)->whereNull('id_perusahaan')->exists();
                if ($exists) {
                    continue;
                }
                DB::table('izin_peran')->insert([
                    'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null,
                    'kode_peran' => $kodePeran, 'id_menu' => $this->idMenu, 'aksi' => $aksi,
                    'diizinkan' => $aksi === 'lihat' ? 1 : ($kodePeran === 'SUPERADMIN' ? 1 : 0),
                    'dibuat_pada' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu')->where('id_menu', $this->idMenu)->delete();
    }
};
