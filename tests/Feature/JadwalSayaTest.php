<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JadwalSayaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rute GET /api/v1/jadwal/saya dilindungi middleware `izin:jadwal`. Di
     * produksi, IzinPeranSeeder memberi role SUPIR izin eksplisit 'lihat'
     * untuk menu '/jadwal' (lihat database/seeders/IzinPeranSeeder.php).
     * RefreshDatabase tidak menjalankan seeder otomatis, jadi izin itu perlu
     * disiapkan manual di sini — pola yang sama dengan
     * tests/Feature/IzinPeranMiddlewareTest.php.
     */
    private function seedIzinJadwalSupir(): void
    {
        $idMenu = (string) Str::uuid();

        DB::table('menu')->insert([
            'id_menu'     => $idMenu,
            'nama_menu'   => 'Jadwal',
            'path'        => '/jadwal',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);

        DB::table('izin_peran')->insert([
            'id_izin'     => (string) Str::uuid(),
            'kode_peran'  => 'SUPIR',
            'id_menu'     => $idMenu,
            'aksi'        => 'lihat',
            'diizinkan'   => 1,
            'dibuat_pada' => now(),
        ]);
    }

    public function test_jadwal_saya_mengembalikan_jadwal_milik_supir_yang_login(): void
    {
        $this->ensurePerusahaan();
        $this->seedIzinJadwalSupir();

        $idPengguna = (string) Str::uuid();
        $pengguna = Pengguna::create([
            'id_pengguna'   => $idPengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPIR',
            'username'      => 'supir_test',
            'email'         => 'supir@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        DB::table('supir')->insert([
            'id_supir'      => (string) Str::uuid(),
            'id_pengguna'   => $idPengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Login Test',
            'no_sim'        => 'SIM-' . Str::random(6),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);

        Sanctum::actingAs($pengguna, ['*']);

        $res = $this->getJson('/api/v1/jadwal/saya');

        // ApiResponse::paginated() mengembalikan amplop {data, meta} tanpa key
        // 'success' (lihat app/Helpers/ApiResponse.php dan kontrak paginated
        // di CLAUDE.md) — bukan amplop {success, message, data, timestamp}
        // milik ApiResponse::success().
        $res->assertStatus(200)->assertJsonStructure(['data', 'meta']);
    }

    public function test_jadwal_saya_tanpa_data_supir_mengembalikan_404(): void
    {
        $this->ensurePerusahaan();
        $this->seedIzinJadwalSupir();

        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPIR',
            'username'      => 'bukan_supir',
            'email'         => 'bukansupir@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        Sanctum::actingAs($pengguna, ['*']);

        $res = $this->getJson('/api/v1/jadwal/saya');

        $res->assertStatus(404);
    }
}
