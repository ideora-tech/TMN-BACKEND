<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $menu = ['/cuti', '/absensi'];

    private array $peran = ['MANAGER', 'ADMIN'];

    private array $aksi = ['lihat', 'tambah', 'ubah', 'hapus'];

    public function up(): void
    {
        $now = now();

        foreach ($this->menu as $path) {
            $idMenu = DB::table('menu')->where('path', $path)->whereNull('dihapus_pada')->value('id_menu');
            if ($idMenu === null) {
                continue;
            }

            foreach ($this->peran as $kodePeran) {
                foreach ($this->aksi as $aksi) {
                    $sudahAda = DB::table('izin_peran')
                        ->where('id_menu', $idMenu)
                        ->where('kode_peran', $kodePeran)
                        ->where('aksi', $aksi)
                        ->whereNull('id_perusahaan')
                        ->exists();

                    if ($sudahAda) {
                        continue;
                    }

                    DB::table('izin_peran')->insert([
                        'id_izin'       => (string) Str::uuid(),
                        'id_perusahaan' => null,
                        'kode_peran'    => $kodePeran,
                        'id_menu'       => $idMenu,
                        'aksi'          => $aksi,
                        'diizinkan'     => 1,
                        'dibuat_pada'   => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
    }
};
