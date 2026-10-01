<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $kodePeran = 'DISPATCHER';

    public function up(): void
    {
        $idMenu = DB::table('menu')->where('path', '/permintaan-vendor')->whereNull('dihapus_pada')->value('id_menu');
        if ($idMenu === null) {
            return;
        }

        DB::table('menu_peran')->insertOrIgnore([['id_menu' => $idMenu, 'kode_peran' => $this->kodePeran]]);

        $sudahAda = DB::table('izin_peran')
            ->where('id_menu', $idMenu)
            ->where('kode_peran', $this->kodePeran)
            ->where('aksi', 'lihat')
            ->whereNull('id_perusahaan')
            ->exists();

        if ($sudahAda) {
            return;
        }

        DB::table('izin_peran')->insert([
            'id_izin'       => (string) Str::uuid(),
            'id_perusahaan' => null,
            'kode_peran'    => $this->kodePeran,
            'id_menu'       => $idMenu,
            'aksi'          => 'lihat',
            'diizinkan'     => 1,
            'dibuat_pada'   => now(),
        ]);
    }

    public function down(): void
    {
        $idMenu = DB::table('menu')->where('path', '/permintaan-vendor')->value('id_menu');
        if ($idMenu === null) {
            return;
        }

        DB::table('izin_peran')
            ->where('id_menu', $idMenu)
            ->where('kode_peran', $this->kodePeran)
            ->where('aksi', 'lihat')
            ->whereNull('id_perusahaan')
            ->delete();
        DB::table('menu_peran')->where('id_menu', $idMenu)->where('kode_peran', $this->kodePeran)->delete();
    }
};
