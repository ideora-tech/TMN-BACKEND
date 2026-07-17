<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\LokasiKotaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LokasiKotaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_mengisi_98_kota_dan_idempoten(): void
    {
        $this->ensurePerusahaan();

        $this->seed(LokasiKotaSeeder::class);
        $this->assertSame(98, DB::table('lokasi')->where('id_perusahaan', self::PERUSAHAAN_ID)->count());

        // Jalankan kedua kali — tidak boleh menduplikasi.
        $this->seed(LokasiKotaSeeder::class);
        $this->assertSame(98, DB::table('lokasi')->where('id_perusahaan', self::PERUSAHAAN_ID)->count());

        $this->assertDatabaseHas('lokasi', [
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_lokasi'   => 'Surabaya',
            'kota'          => 'Surabaya',
            'aktif'         => 1,
        ]);
    }

    public function test_seeder_menjangkau_semua_perusahaan_dan_tidak_menyentuh_lokasi_manual(): void
    {
        $this->ensurePerusahaan();

        // Lokasi manual user (bukan nama kota) — tidak boleh tersentuh.
        DB::table('lokasi')->insert([
            'id_lokasi'     => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_lokasi'   => 'Gudang Cikarang',
            'kota'          => 'Bekasi',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        // Kota yang kebetulan sudah dibuat manual — seeder harus skip, bukan duplikasi.
        DB::table('lokasi')->insert([
            'id_lokasi'     => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_lokasi'   => 'Medan',
            'kota'          => 'Medan',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        $this->seed(LokasiKotaSeeder::class);

        // 98 kota, tapi Medan sudah ada manual → 97 baru + 2 manual = 99.
        $this->assertSame(99, DB::table('lokasi')->where('id_perusahaan', self::PERUSAHAAN_ID)->count());
        $this->assertSame(1, DB::table('lokasi')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)
            ->where('nama_lokasi', 'Medan')
            ->count());
        $this->assertDatabaseHas('lokasi', [
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_lokasi'   => 'Gudang Cikarang',
        ]);

        // Tenant lain juga kebagian penuh.
        $this->assertSame(98, DB::table('lokasi')->where('id_perusahaan', $idPerusahaanLain)->count());
    }
}
