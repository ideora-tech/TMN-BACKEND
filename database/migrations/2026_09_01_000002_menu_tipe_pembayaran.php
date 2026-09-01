<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenu       = 'm0000001-0000-4000-8000-000000000095';
    private string $idDataMaster = 'm0000001-0000-4000-8000-000000000050';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Tipe Pembayaran', 'path' => '/tipe-pembayaran',
                'icon' => 'creditCard', 'id_menu_induk' => $this->idDataMaster, 'urutan' => 8,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenu, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'SUPERADMIN'],
        ]);

        // KEUANGAN full akses (yang input invoice vendor); MANAGER & ADMIN
        // hanya lihat — baris diizinkan=0 dibuat eksplisit supaya
        // IzinPeranSeeder (skip baris yang sudah ada) tidak menimpanya jadi 1.
        $izin = [
            ['KEUANGAN', 'lihat', 1],
            ['KEUANGAN', 'tambah', 1],
            ['KEUANGAN', 'ubah', 1],
            ['KEUANGAN', 'hapus', 1],
            ['MANAGER', 'lihat', 1],
            ['MANAGER', 'tambah', 0],
            ['MANAGER', 'ubah', 0],
            ['MANAGER', 'hapus', 0],
            ['ADMIN', 'lihat', 1],
            ['ADMIN', 'tambah', 0],
            ['ADMIN', 'ubah', 0],
            ['ADMIN', 'hapus', 0],
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
        // Sengaja no-op — lihat pola menu_invoice_vendor.php.
    }
};
