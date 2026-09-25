<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idGrup       = 'm0000001-0000-4000-8000-000000000098';
    private string $idPermintaan = 'm0000001-0000-4000-8000-000000000099';
    private string $idBarang     = 'm0000001-0000-4000-8000-000000000100';

    private array $peranPenuh   = ['SUPERADMIN', 'ADMIN', 'PENGADAAN'];
    private array $peranPemohon = ['MANAGER', 'DISPATCHER', 'KEUANGAN', 'SALES'];

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idGrup, 'nama_menu' => 'Pengadaan', 'path' => null,
                'icon' => 'handCoins', 'id_menu_induk' => null, 'urutan' => 11,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
            [
                'id_menu' => $this->idPermintaan, 'nama_menu' => 'Permintaan Pembelian (PR)', 'path' => '/permintaan-pembelian',
                'icon' => 'clipboard', 'id_menu_induk' => $this->idGrup, 'urutan' => 1,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
            [
                'id_menu' => $this->idBarang, 'nama_menu' => 'Master Barang', 'path' => '/master-barang',
                'icon' => 'database', 'id_menu_induk' => $this->idGrup, 'urutan' => 2,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        $menuPeran = [];
        $izin = [];
        foreach ($this->peranPenuh as $peran) {
            foreach ([$this->idGrup, $this->idPermintaan, $this->idBarang] as $idMenu) {
                $menuPeran[] = ['id_menu' => $idMenu, 'kode_peran' => $peran];
            }
            foreach ([$this->idPermintaan, $this->idBarang] as $idMenu) {
                foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
                    $izin[] = [$idMenu, $peran, $aksi, 1];
                }
            }
        }
        foreach ($this->peranPemohon as $peran) {
            foreach ([$this->idGrup, $this->idPermintaan, $this->idBarang] as $idMenu) {
                $menuPeran[] = ['id_menu' => $idMenu, 'kode_peran' => $peran];
            }
            foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
                $izin[] = [$this->idPermintaan, $peran, $aksi, 1];
            }
            $izin[] = [$this->idBarang, $peran, 'lihat', 1];
        }

        DB::table('menu_peran')->insertOrIgnore($menuPeran);

        foreach ($izin as [$idMenu, $kodePeran, $aksi, $diizinkan]) {
            $ada = DB::table('izin_peran')
                ->where('id_menu', $idMenu)->where('kode_peran', $kodePeran)
                ->where('aksi', $aksi)->whereNull('id_perusahaan')->exists();
            if ($ada) {
                continue;
            }
            DB::table('izin_peran')->insert([
                'id_izin'       => (string) Str::uuid(),
                'id_perusahaan' => null,
                'kode_peran'    => $kodePeran,
                'id_menu'       => $idMenu,
                'aksi'          => $aksi,
                'diizinkan'     => $diizinkan,
                'dibuat_pada'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ([$this->idPermintaan, $this->idBarang, $this->idGrup] as $idMenu) {
            DB::table('izin_peran')->where('id_menu', $idMenu)->delete();
            DB::table('menu_peran')->where('id_menu', $idMenu)->delete();
            DB::table('menu')->where('id_menu', $idMenu)->delete();
        }
    }
};
