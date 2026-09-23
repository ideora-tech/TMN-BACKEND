<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenu = 'm0000001-0000-4000-8000-000000000096';
    private string $kodePeran = 'SALES';
    private array $aksi = ['lihat', 'tambah'];

    public function up(): void
    {
        if (!DB::table('menu')->where('id_menu', $this->idMenu)->exists()) {
            return;
        }

        $now = now();

        DB::table('menu_peran')->insertOrIgnore([['id_menu' => $this->idMenu, 'kode_peran' => $this->kodePeran]]);

        foreach ($this->aksi as $aksi) {
            $sudahAda = DB::table('izin_peran')
                ->where('id_menu', $this->idMenu)
                ->where('kode_peran', $this->kodePeran)
                ->where('aksi', $aksi)
                ->whereNull('id_perusahaan')
                ->exists();

            if ($sudahAda) {
                DB::table('izin_peran')
                    ->where('id_menu', $this->idMenu)
                    ->where('kode_peran', $this->kodePeran)
                    ->where('aksi', $aksi)
                    ->whereNull('id_perusahaan')
                    ->update(['diizinkan' => 1, 'dihapus_pada' => null, 'dihapus_oleh' => null, 'diubah_pada' => $now]);
                continue;
            }

            DB::table('izin_peran')->insert([
                'id_izin'       => (string) Str::uuid(),
                'id_perusahaan' => null,
                'kode_peran'    => $this->kodePeran,
                'id_menu'       => $this->idMenu,
                'aksi'          => $aksi,
                'diizinkan'     => 1,
                'dibuat_pada'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')
            ->where('id_menu', $this->idMenu)
            ->where('kode_peran', $this->kodePeran)
            ->whereIn('aksi', $this->aksi)
            ->whereNull('id_perusahaan')
            ->delete();

        DB::table('menu_peran')->where('id_menu', $this->idMenu)->where('kode_peran', $this->kodePeran)->delete();
    }
};
