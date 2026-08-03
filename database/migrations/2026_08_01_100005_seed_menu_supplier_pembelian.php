<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenuSupplier = 'm0000001-0000-4000-8000-000000000085';
    private string $idMenuPembelian = 'm0000001-0000-4000-8000-000000000086';
    private string $idGrupPemeliharaan = 'm0000001-0000-4000-8000-000000000080';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuSupplier, 'nama_menu' => 'Supplier', 'path' => '/supplier',
                'icon' => 'store', 'id_menu_induk' => $this->idGrupPemeliharaan, 'urutan' => 10,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
            [
                'id_menu' => $this->idMenuPembelian, 'nama_menu' => 'Pembelian Sparepart', 'path' => '/pembelian-sparepart',
                'icon' => 'shopping-cart', 'id_menu_induk' => $this->idGrupPemeliharaan, 'urutan' => 11,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuSupplier, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idMenuSupplier, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuSupplier, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuSupplier, 'kode_peran' => 'SUPERADMIN'],
            ['id_menu' => $this->idMenuPembelian, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idMenuPembelian, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuPembelian, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenuPembelian, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuPembelian, 'kode_peran' => 'SUPERADMIN'],
        ]);

        $izin = [
            [$this->idMenuSupplier, 'DISPATCHER', 'lihat', 1],
            [$this->idMenuSupplier, 'DISPATCHER', 'tambah', 1],
            [$this->idMenuSupplier, 'DISPATCHER', 'ubah', 1],
            [$this->idMenuSupplier, 'DISPATCHER', 'hapus', 0],
            [$this->idMenuSupplier, 'MANAGER', 'lihat', 1],
            [$this->idMenuSupplier, 'MANAGER', 'tambah', 0],
            [$this->idMenuSupplier, 'MANAGER', 'ubah', 0],
            [$this->idMenuSupplier, 'MANAGER', 'hapus', 0],
            [$this->idMenuSupplier, 'ADMIN', 'lihat', 1],
            [$this->idMenuSupplier, 'ADMIN', 'tambah', 1],
            [$this->idMenuSupplier, 'ADMIN', 'ubah', 1],
            [$this->idMenuSupplier, 'ADMIN', 'hapus', 1],
            [$this->idMenuPembelian, 'DISPATCHER', 'lihat', 1],
            [$this->idMenuPembelian, 'DISPATCHER', 'tambah', 1],
            [$this->idMenuPembelian, 'DISPATCHER', 'ubah', 1],
            [$this->idMenuPembelian, 'DISPATCHER', 'hapus', 1],
            [$this->idMenuPembelian, 'MANAGER', 'lihat', 1],
            [$this->idMenuPembelian, 'MANAGER', 'tambah', 0],
            [$this->idMenuPembelian, 'MANAGER', 'ubah', 1],
            [$this->idMenuPembelian, 'MANAGER', 'hapus', 0],
            [$this->idMenuPembelian, 'KEUANGAN', 'lihat', 1],
            [$this->idMenuPembelian, 'KEUANGAN', 'tambah', 0],
            [$this->idMenuPembelian, 'KEUANGAN', 'ubah', 1],
            [$this->idMenuPembelian, 'KEUANGAN', 'hapus', 0],
            [$this->idMenuPembelian, 'ADMIN', 'lihat', 1],
            [$this->idMenuPembelian, 'ADMIN', 'tambah', 1],
            [$this->idMenuPembelian, 'ADMIN', 'ubah', 1],
            [$this->idMenuPembelian, 'ADMIN', 'hapus', 1],
        ];

        foreach ($izin as [$idMenu, $kodePeran, $aksi, $diizinkan]) {
            $exists = DB::table('izin_peran')
                ->where('id_menu', $idMenu)
                ->where('kode_peran', $kodePeran)
                ->where('aksi', $aksi)
                ->whereNull('id_perusahaan')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('izin_peran')->insert([
                'id_izin' => (string) Str::uuid(),
                'id_perusahaan' => null,
                'kode_peran' => $kodePeran,
                'id_menu' => $idMenu,
                'aksi' => $aksi,
                'diizinkan' => $diizinkan,
                'dibuat_pada' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Sengaja no-op: menu, menu_peran, dan izin_peran bisa saja sudah diubah
        // admin lewat UI setelah migrasi ini jalan.
    }
};
