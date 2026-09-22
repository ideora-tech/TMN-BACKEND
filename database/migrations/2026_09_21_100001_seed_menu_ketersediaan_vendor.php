<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenu       = 'm0000001-0000-4000-8000-000000000097';
    private string $idGrupVendor = 'm0000001-0000-4000-8000-000000000070';

    private array $peranLihat = ['SUPERADMIN', 'ADMIN', 'MANAGER', 'DISPATCHER', 'SALES'];

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Ketersediaan Vendor', 'path' => '/ketersediaan-vendor',
                'icon' => 'carProfile', 'id_menu_induk' => $this->idGrupVendor, 'urutan' => 8,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        foreach ($this->peranLihat as $kodePeran) {
            DB::table('menu_peran')->insertOrIgnore([['id_menu' => $this->idMenu, 'kode_peran' => $kodePeran]]);

            $sudahAda = DB::table('izin_peran')
                ->where('id_menu', $this->idMenu)
                ->where('kode_peran', $kodePeran)
                ->where('aksi', 'lihat')
                ->whereNull('id_perusahaan')
                ->exists();

            if ($sudahAda) {
                continue;
            }

            DB::table('izin_peran')->insert([
                'id_izin'       => (string) Str::uuid(),
                'id_perusahaan' => null,
                'kode_peran'    => $kodePeran,
                'id_menu'       => $this->idMenu,
                'aksi'          => 'lihat',
                'diizinkan'     => 1,
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
