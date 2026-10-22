<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuPenugasanKonsolidasiMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function jalankanMigrasi(): void
    {
        $migrasi = require database_path('migrations/2026_10_23_100000_menu_penugasan_konsolidasi_klien.php');
        $migrasi->up();
    }

    public function test_database_baru_mendapat_menu_penugasan_dan_konsolidasi_klien_beserta_izin(): void
    {
        $penugasan = DB::table('menu')->where('path', '/penugasan')->whereNull('dihapus_pada')->first();
        $konsolidasi = DB::table('menu')->where('path', '/konsolidasi-klien')->whereNull('dihapus_pada')->first();

        $this->assertNotNull($penugasan);
        $this->assertSame('m0000001-0000-4000-8000-000000000020', $penugasan->id_menu_induk);
        $this->assertNotNull($konsolidasi);
        $this->assertSame('m0000001-0000-4000-8000-000000000030', $konsolidasi->id_menu_induk);

        $izin = fn (string $idMenu, string $peran) => DB::table('izin_peran')
            ->where('id_menu', $idMenu)->where('kode_peran', $peran)->whereNull('id_perusahaan')->where('diizinkan', 1)
            ->orderBy('aksi')->pluck('aksi')->all();
        $this->assertSame(['hapus', 'lihat', 'tambah', 'ubah'], $izin($penugasan->id_menu, 'OPS'));
        $this->assertSame(['lihat'], $izin($penugasan->id_menu, 'KEUANGAN'));
        $this->assertSame(['lihat'], $izin($konsolidasi->id_menu, 'KEUANGAN'));
    }

    public function test_tidak_menggandakan_menu_yang_sudah_ada_dan_aman_dijalankan_ulang(): void
    {
        $idBawaan = DB::table('menu')->where('path', '/penugasan')->value('id_menu');
        DB::table('izin_peran')->where('id_menu', $idBawaan)->delete();
        DB::table('menu_peran')->where('id_menu', $idBawaan)->delete();
        DB::table('menu')->where('id_menu', $idBawaan)->delete();
        DB::table('menu')->insert([
            'id_menu' => 'menu-penugasan-lama', 'nama_menu' => 'Penugasan', 'path' => '/penugasan',
            'id_menu_induk' => 'm0000001-0000-4000-8000-000000000020', 'urutan' => 4, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->jalankanMigrasi();
        $this->jalankanMigrasi();

        $this->assertSame(1, DB::table('menu')->where('path', '/penugasan')->whereNull('dihapus_pada')->count());
        $this->assertSame(1, DB::table('menu')->where('path', '/konsolidasi-klien')->whereNull('dihapus_pada')->count());
        $this->assertSame(1, DB::table('izin_peran')->where('id_menu', 'menu-penugasan-lama')->where('kode_peran', 'OPS')->where('aksi', 'lihat')->count());
    }
}
