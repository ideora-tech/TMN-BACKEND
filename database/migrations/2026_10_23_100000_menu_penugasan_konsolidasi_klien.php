<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const SEMUA = ['lihat', 'tambah', 'ubah', 'hapus'];

    private function daftarMenu(): array
    {
        return [
            [
                'id_menu'       => 'd2f6b8a4-3c1e-4f7a-9b5d-8e2c4a6f1b30',
                'nama_menu'     => 'Penugasan',
                'path'          => '/penugasan',
                'id_menu_induk' => 'm0000001-0000-4000-8000-000000000020',
                'urutan'        => 4,
                'izin'          => [
                    'ADMIN' => self::SEMUA, 'DISPATCHER' => self::SEMUA, 'MANAGER' => self::SEMUA, 'SALES' => self::SEMUA, 'OPS' => self::SEMUA,
                    'BOD' => ['lihat'], 'KEUANGAN' => ['lihat'], 'SUPIR' => ['lihat'],
                ],
            ],
            [
                'id_menu'       => 'e7a3c5d1-9f2b-4e8a-b6c4-1d3f5a7e9c52',
                'nama_menu'     => 'Konsolidasi Klien',
                'path'          => '/konsolidasi-klien',
                'id_menu_induk' => 'm0000001-0000-4000-8000-000000000030',
                'urutan'        => 1,
                'izin'          => [
                    'ADMIN' => ['lihat'], 'BOD' => ['lihat'], 'KEUANGAN' => ['lihat'], 'MANAGER' => ['lihat'],
                    'DISPATCHER' => ['lihat'], 'SALES' => ['lihat'],
                ],
            ],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->daftarMenu() as $menu) {
            $idMenu = DB::table('menu')->where('path', $menu['path'])->whereNull('dihapus_pada')->value('id_menu');

            if ($idMenu === null) {
                $idMenu = $menu['id_menu'];
                DB::table('menu')->insert([
                    'id_menu'       => $idMenu,
                    'nama_menu'     => $menu['nama_menu'],
                    'path'          => $menu['path'],
                    'icon'          => null,
                    'id_menu_induk' => $menu['id_menu_induk'],
                    'urutan'        => $menu['urutan'],
                    'aktif'         => 1,
                    'dibuat_pada'   => $now,
                ]);
            }

            foreach ($menu['izin'] as $kodePeran => $aksiList) {
                DB::table('menu_peran')->insertOrIgnore(['id_menu' => $idMenu, 'kode_peran' => $kodePeran]);

                foreach ($aksiList as $aksi) {
                    $ada = DB::table('izin_peran')
                        ->where('id_menu', $idMenu)
                        ->where('kode_peran', $kodePeran)
                        ->where('aksi', $aksi)
                        ->whereNull('id_perusahaan')
                        ->exists();
                    if ($ada) {
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
        foreach ($this->daftarMenu() as $menu) {
            DB::table('izin_peran')->where('id_menu', $menu['id_menu'])->delete();
            DB::table('menu_peran')->where('id_menu', $menu['id_menu'])->delete();
            DB::table('menu')->where('id_menu', $menu['id_menu'])->delete();
        }
    }
};
