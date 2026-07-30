<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MenuAksesPeranTest extends TestCase
{
    use RefreshDatabase;

    private function makeMenu(string $nama, array $kodePeran = []): string
    {
        $id = (string) Str::uuid();
        DB::table('menu')->insert([
            'id_menu' => $id, 'nama_menu' => $nama, 'path' => '/' . Str::slug($nama),
            'urutan' => 99, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        foreach ($kodePeran as $kode) {
            DB::table('menu_peran')->insert(['id_menu' => $id, 'kode_peran' => $kode]);
        }
        return $id;
    }

    private function makePeran(string $kode): void
    {
        DB::table('peran')->insertOrIgnore([
            'id_peran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => $kode, 'nama_peran' => $kode, 'dibuat_pada' => now(),
        ]);
    }

    private function kodePeranMenu(string $idMenu): array
    {
        return DB::table('menu_peran')->where('id_menu', $idMenu)->orderBy('kode_peran')->pluck('kode_peran')->all();
    }

    public function test_list_akses_peran_menampilkan_kode_peran_tiap_menu(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idTerbuka   = $this->makeMenu('Menu Terbuka');
        $idTerbatas  = $this->makeMenu('Menu Terbatas', ['SUPERADMIN', 'MANAGER']);

        $res = $this->getJson('/api/v1/menu/akses-peran');

        $res->assertStatus(200);
        $data = collect($res->json('data'));
        $this->assertSame([], $data->firstWhere('id_menu', $idTerbuka)['kode_peran']);
        $this->assertEqualsCanonicalizing(['SUPERADMIN', 'MANAGER'], $data->firstWhere('id_menu', $idTerbatas)['kode_peran']);
    }

    public function test_akses_peran_hanya_untuk_superadmin(): void
    {
        $this->actingAsRole('MANAGER');
        $this->getJson('/api/v1/menu/akses-peran')->assertStatus(403);
    }

    public function test_menambahkan_peran_ke_menu_terbatas(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makePeran('MANAGER');
        $idMenu = $this->makeMenu('Menu Payroll', ['SUPERADMIN']);

        $res = $this->putJson('/api/v1/menu/akses-peran/MANAGER', ['id_menu' => [$idMenu]]);

        $res->assertStatus(200);
        $this->assertEqualsCanonicalizing(['SUPERADMIN', 'MANAGER'], $this->kodePeranMenu($idMenu));
    }

    public function test_mencabut_peran_dari_menu_terbuka_memmaterialisasi_peran_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makePeran('SUPERADMIN');
        $this->makePeran('MANAGER');
        $this->makePeran('OPERASIONAL');
        $idMenu = $this->makeMenu('Menu Umum');

        $res = $this->putJson('/api/v1/menu/akses-peran/MANAGER', ['id_menu' => []]);

        $res->assertStatus(200);
        $sisa = $this->kodePeranMenu($idMenu);
        $this->assertNotContains('MANAGER', $sisa);
        $this->assertContains('OPERASIONAL', $sisa);
        $this->assertContains('SUPERADMIN', $sisa);
    }

    public function test_mencabut_peran_terakhir_fallback_ke_superadmin(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makePeran('MANAGER');
        $idMenu = $this->makeMenu('Menu Khusus Manager', ['MANAGER']);

        $res = $this->putJson('/api/v1/menu/akses-peran/MANAGER', ['id_menu' => []]);

        $res->assertStatus(200);
        $this->assertSame(['SUPERADMIN'], $this->kodePeranMenu($idMenu));
    }

    public function test_peran_tidak_dikenal_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/v1/menu/akses-peran/TIDAK-ADA', ['id_menu' => []])->assertStatus(404);
    }

    public function test_hasil_simpan_tercermin_di_menu_tree(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makePeran('MANAGER');
        $idMenu = $this->makeMenu('Menu Uji Tree', ['SUPERADMIN']);

        $this->putJson('/api/v1/menu/akses-peran/MANAGER', ['id_menu' => [$idMenu]])->assertStatus(200);

        $this->actingAsRole('MANAGER');
        $res = $this->getJson('/api/v1/menu/tree');
        $res->assertStatus(200);
        $this->assertNotNull(collect($res->json('data'))->firstWhere('id_menu', $idMenu));
    }
}
