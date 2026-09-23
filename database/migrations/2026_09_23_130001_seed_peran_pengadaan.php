<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $kodePeran = 'PENGADAAN';

    private array $izinPerPath = [
        '/pembelian-sparepart' => ['lihat', 'tambah', 'ubah', 'hapus'],
        '/sparepart'           => ['lihat', 'tambah', 'ubah'],
        '/supplier'            => ['lihat', 'tambah', 'ubah'],
        '/perawatan-armada'    => ['lihat'],
        '/permintaan-vendor'   => ['lihat', 'tambah', 'ubah', 'hapus'],
        '/kontrak-vendor'      => ['lihat', 'tambah', 'ubah'],
        '/ketersediaan-vendor' => ['lihat'],
        '/persetujuan-saya'    => ['lihat'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan') as $idPerusahaan) {
            $ada = DB::table('peran')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('kode_peran', $this->kodePeran)
                ->exists();
            if (!$ada) {
                DB::table('peran')->insert([
                    'id_peran'      => (string) Str::uuid(),
                    'id_perusahaan' => $idPerusahaan,
                    'kode_peran'    => $this->kodePeran,
                    'nama_peran'    => 'Pengadaan',
                    'is_platform'   => 0,
                    'aktif'         => 1,
                    'dibuat_pada'   => $now,
                ]);
            }
        }

        foreach ($this->izinPerPath as $path => $daftarAksi) {
            $idMenu = DB::table('menu')->where('path', $path)->whereNull('dihapus_pada')->value('id_menu');
            if ($idMenu === null) {
                continue;
            }

            DB::table('menu_peran')->insertOrIgnore([['id_menu' => $idMenu, 'kode_peran' => $this->kodePeran]]);

            foreach ($daftarAksi as $aksi) {
                $sudahAda = DB::table('izin_peran')
                    ->where('id_menu', $idMenu)
                    ->where('kode_peran', $this->kodePeran)
                    ->where('aksi', $aksi)
                    ->whereNull('id_perusahaan')
                    ->exists();
                if ($sudahAda) {
                    continue;
                }
                DB::table('izin_peran')->insert([
                    'id_izin'       => (string) Str::uuid(),
                    'id_perusahaan' => null,
                    'kode_peran'    => $this->kodePeran,
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
        $idMenus = DB::table('menu')->whereIn('path', array_keys($this->izinPerPath))->pluck('id_menu');
        DB::table('izin_peran')->where('kode_peran', $this->kodePeran)->whereIn('id_menu', $idMenus)->whereNull('id_perusahaan')->delete();
        DB::table('menu_peran')->where('kode_peran', $this->kodePeran)->whereIn('id_menu', $idMenus)->delete();
    }
};
