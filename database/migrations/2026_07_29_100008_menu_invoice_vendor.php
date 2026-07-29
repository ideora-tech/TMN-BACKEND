<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenuInvoiceVendor = 'm0000001-0000-4000-8000-000000000034';
    private string $idKeuangan          = 'm0000001-0000-4000-8000-000000000030';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuInvoiceVendor, 'nama_menu' => 'Invoice Vendor', 'path' => '/invoice-vendor',
                'icon' => 'receipt', 'id_menu_induk' => $this->idKeuangan, 'urutan' => 4,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuInvoiceVendor, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenuInvoiceVendor, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuInvoiceVendor, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuInvoiceVendor, 'kode_peran' => 'SUPERADMIN'],
        ]);

        // KEUANGAN full akses; MANAGER & ADMIN hanya lihat — baris tambah/ubah/hapus
        // dibuat eksplisit diizinkan=0 supaya IzinPeranSeeder (yang meng-skip baris
        // yang sudah ada) tidak menimpanya menjadi 1.
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
                ->where('id_menu', $this->idMenuInvoiceVendor)
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
                'id_menu'       => $this->idMenuInvoiceVendor,
                'aksi'          => $aksi,
                'diizinkan'     => $diizinkan,
                'dibuat_pada'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Sengaja no-op: menu, menu_peran, dan izin_peran bisa saja sudah diubah
        // admin lewat UI setelah migrasi ini jalan — rollback otomatis berisiko
        // menghapus konfigurasi yang valid. Bila perlu, bersihkan manual via
        // id_menu m0000001-0000-4000-8000-000000000034 atau re-seed MenuSeeder.
    }
};
