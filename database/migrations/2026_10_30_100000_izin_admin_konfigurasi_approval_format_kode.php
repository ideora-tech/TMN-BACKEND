<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PATH = ['/konfigurasi-approval', '/format-kode'];
    private const AKSI = ['lihat', 'tambah', 'ubah', 'hapus'];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('menu')->whereIn('path', self::PATH)->whereNull('dihapus_pada')->pluck('id_menu') as $idMenu) {
            DB::table('menu_peran')->insertOrIgnore(['id_menu' => $idMenu, 'kode_peran' => 'ADMIN']);

            foreach (self::AKSI as $aksi) {
                $ada = DB::table('izin_peran')
                    ->where('id_menu', $idMenu)
                    ->where('kode_peran', 'ADMIN')
                    ->where('aksi', $aksi)
                    ->whereNull('id_perusahaan')
                    ->exists();
                if ($ada) {
                    continue;
                }

                DB::table('izin_peran')->insert([
                    'id_izin'       => (string) Str::uuid(),
                    'id_perusahaan' => null,
                    'kode_peran'    => 'ADMIN',
                    'id_menu'       => $idMenu,
                    'aksi'          => $aksi,
                    'diizinkan'     => 1,
                    'dibuat_pada'   => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
    }
};
