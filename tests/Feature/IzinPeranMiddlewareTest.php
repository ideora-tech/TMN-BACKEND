<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IzinPeranMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'aaaa1111-0000-4000-8000-000000000001';
    private const ID_MENU_VENDOR = 'aaaa1111-0000-4000-8000-000000000002';

    private function seedMenuTrip(): string
    {
        DB::table('menu')->insertOrIgnore([
            'id_menu'     => self::ID_MENU_TRIP,
            'nama_menu'   => 'Trip Monitor',
            'path'        => '/trip',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);

        return self::ID_MENU_TRIP;
    }

    private function seedMenuVendor(): string
    {
        DB::table('menu')->insertOrIgnore([
            'id_menu'     => self::ID_MENU_VENDOR,
            'nama_menu'   => 'Vendor',
            'path'        => '/vendor',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);

        return self::ID_MENU_VENDOR;
    }

    private function seedIzin(string $idMenu, string $kodePeran, string $aksi, int $diizinkan = 1): void
    {
        DB::table('izin_peran')->insert([
            'id_izin'     => (string) Str::uuid(),
            'kode_peran'  => $kodePeran,
            'id_menu'     => $idMenu,
            'aksi'        => $aksi,
            'diizinkan'   => $diizinkan,
            'dibuat_pada' => now(),
        ]);
    }

    public function test_role_tanpa_baris_izin_ditolak_403(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->seedMenuTrip();

        $res = $this->getJson('/api/trip');

        $res->assertStatus(403)
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk aksi ini');
    }

    public function test_role_dengan_izin_lihat_boleh_get_tapi_post_tetap_ditolak(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idMenu = $this->seedMenuTrip();
        $this->seedIzin($idMenu, 'KEUANGAN', 'lihat', 1);

        $get = $this->getJson('/api/trip');
        $get->assertStatus(200);

        $post = $this->postJson('/api/trip', []);
        $post->assertStatus(403);
    }

    public function test_superadmin_bypass_tanpa_baris_izin(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->seedMenuTrip();

        $res = $this->getJson('/api/trip');

        $res->assertStatus(200);
    }

    public function test_admin_tanpa_baris_izin_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $this->seedMenuTrip();

        $this->getJson('/api/trip')->assertStatus(403);
    }

    public function test_admin_dengan_baris_izin_diizinkan(): void
    {
        $this->actingAsRole('ADMIN');
        $idMenu = $this->seedMenuTrip();
        $this->seedIzin($idMenu, 'ADMIN', 'lihat', 1);

        $this->getJson('/api/trip')->assertStatus(200);
    }

    public function test_dispatcher_dengan_semua_aksi_diizinkan_boleh_post(): void
    {
        $this->actingAsRole('DISPATCHER');
        $idMenu = $this->seedMenuTrip();
        foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
            $this->seedIzin($idMenu, 'DISPATCHER', $aksi, 1);
        }

        $res = $this->postJson('/api/trip', []);

        $this->assertContains($res->status(), [201, 422]);
    }

    // ── C1: SUPIR terkunci — izin eksplisit per role dari IzinPeranSeeder ──────

    public function test_supir_dengan_seed_default_bisa_akses_trip(): void
    {
        $this->actingAsRole('SUPIR');
        $idMenu = $this->seedMenuTrip();
        $this->seedIzin($idMenu, 'SUPIR', 'lihat', 1);

        $res = $this->getJson('/api/trip');

        $res->assertStatus(200);
    }

    public function test_izin_peran_seeder_membuat_izin_eksplisit_supir_untuk_menu_trip(): void
    {
        $this->seed(\Database\Seeders\MenuSeeder::class);
        $this->seed(\Database\Seeders\IzinPeranSeeder::class);

        $idMenuTrip = DB::table('menu')->where('path', '/trip')->value('id_menu');

        $this->assertNotNull($idMenuTrip);
        $this->assertDatabaseHas('izin_peran', [
            'kode_peran' => 'SUPIR',
            'aksi'       => 'lihat',
            'id_menu'    => $idMenuTrip,
        ]);
    }

    // ── T2: /home harus tetap punya izin lihat walau tanpa baris menu_peran ────

    public function test_izin_peran_seeder_memberi_izin_lihat_home_untuk_semua_peran_non_superadmin(): void
    {
        $this->seed(\Database\Seeders\PeranSeeder::class);
        $this->seed(\Database\Seeders\MenuSeeder::class);
        $this->seed(\Database\Seeders\IzinPeranSeeder::class);

        $idMenuHome = DB::table('menu')->where('path', '/home')->value('id_menu');
        $this->assertNotNull($idMenuHome);

        $peranNonSuperadmin = DB::table('peran')
            ->whereRaw('UPPER(kode_peran) != ?', ['SUPERADMIN'])
            ->pluck('kode_peran');

        $this->assertNotEmpty($peranNonSuperadmin);

        foreach ($peranNonSuperadmin as $kodePeran) {
            $this->assertDatabaseHas('izin_peran', [
                'id_menu'       => $idMenuHome,
                'kode_peran'    => strtoupper($kodePeran),
                'aksi'          => 'lihat',
                'diizinkan'     => 1,
                'id_perusahaan' => null,
            ]);
        }

        $this->assertDatabaseMissing('izin_peran', [
            'id_menu'    => $idMenuHome,
            'kode_peran' => 'SUPERADMIN',
        ]);
    }

    public function test_izin_peran_seeder_idempoten_untuk_izin_home(): void
    {
        $this->seed(\Database\Seeders\PeranSeeder::class);
        $this->seed(\Database\Seeders\MenuSeeder::class);
        $this->seed(\Database\Seeders\IzinPeranSeeder::class);
        $this->seed(\Database\Seeders\IzinPeranSeeder::class);

        $idMenuHome = DB::table('menu')->where('path', '/home')->value('id_menu');

        $jumlah = DB::table('izin_peran')
            ->where('id_menu', $idMenuHome)
            ->where('kode_peran', 'ADMIN')
            ->where('aksi', 'lihat')
            ->count();

        $this->assertSame(1, $jumlah);
    }

    // ── I1: deny-precedence per perusahaan pada izin_peran ──────────────────────

    public function test_izin_company_specific_revoke_menang_atas_izin_global(): void
    {
        $pengguna = $this->actingAsRole('KEUANGAN');
        $idMenu = $this->seedMenuTrip();

        // Izin global mengizinkan...
        $this->seedIzin($idMenu, 'KEUANGAN', 'lihat', 1);
        // ...tapi izin per-perusahaan mencabutnya secara eksplisit.
        DB::table('izin_peran')->insert([
            'id_izin'       => (string) Str::uuid(),
            'id_perusahaan' => $pengguna->id_perusahaan,
            'kode_peran'    => 'KEUANGAN',
            'id_menu'       => $idMenu,
            'aksi'          => 'lihat',
            'diizinkan'     => 0,
            'dibuat_pada'   => now(),
        ]);

        $res = $this->getJson('/api/trip');

        $res->assertStatus(403);
    }

    public function test_izin_global_saja_diizinkan_boleh_akses(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idMenu = $this->seedMenuTrip();
        $this->seedIzin($idMenu, 'KEUANGAN', 'lihat', 1);

        $res = $this->getJson('/api/trip');

        $res->assertStatus(200);
    }

    // ── izin:vendor menaungi endpoint armada-vendor & supir-vendor ──────────

    public function test_dispatcher_dengan_izin_vendor_boleh_akses_armada_vendor(): void
    {
        $this->actingAsRole('DISPATCHER');
        $idMenu = $this->seedMenuVendor();
        $this->seedIzin($idMenu, 'DISPATCHER', 'lihat', 1);

        $res = $this->getJson('/api/armada-vendor');

        $res->assertStatus(200);
    }

    public function test_dispatcher_dengan_izin_vendor_boleh_akses_supir_vendor(): void
    {
        $this->actingAsRole('DISPATCHER');
        $idMenu = $this->seedMenuVendor();
        $this->seedIzin($idMenu, 'DISPATCHER', 'lihat', 1);

        $res = $this->getJson('/api/supir-vendor');

        $res->assertStatus(200);
    }

    public function test_dispatcher_tanpa_izin_vendor_ditolak_akses_armada_vendor(): void
    {
        $this->actingAsRole('DISPATCHER');
        $this->seedMenuVendor();

        $res = $this->getJson('/api/armada-vendor');

        $res->assertStatus(403)
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk aksi ini');
    }
}
