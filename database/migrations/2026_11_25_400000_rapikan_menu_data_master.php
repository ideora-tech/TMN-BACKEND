<?php

declare(strict_types=1);

/**
 * Rapikan Data Master: Kategori Pemasukan dipindah ke menu "Master Keuangan"
 * (dua tab), dan Tipe Permintaan jadi tab di halaman Kategori Permintaan.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenu       = 'f3a9c5e7-1b46-4d8a-92c4-7e1b3d5f9a26';
    private string $idDataMaster = 'm0000001-0000-4000-8000-000000000050';

    /** Menu yang sekarang menjadi tab di halaman gabungan; izinnya tetap dipakai API. */
    private const DISEMBUNYIKAN = ['/tipe-permintaan', '/kategori-pemasukan'];

    public function up(): void
    {
        $now = now();

        DB::table('menu')->whereIn('path', self::DISEMBUNYIKAN)->update(['tampil_sidebar' => 0]);

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Master Keuangan', 'path' => '/master-keuangan',
                'icon' => 'wallet', 'id_menu_induk' => $this->idDataMaster, 'urutan' => 13,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenu, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'SUPERADMIN'],
        ]);

        $izin = [
            ['KEUANGAN', 'lihat', 1],
            ['KEUANGAN', 'tambah', 1],
            ['KEUANGAN', 'ubah', 1],
            ['KEUANGAN', 'hapus', 1],
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
        DB::table('menu')->whereIn('path', self::DISEMBUNYIKAN)->update(['tampil_sidebar' => 1]);
        DB::table('izin_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu')->where('id_menu', $this->idMenu)->delete();
    }
};
