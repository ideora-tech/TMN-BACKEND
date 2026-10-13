<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    private function buatMenu(?string $idInduk, ?string $path, string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('menu')->insert([
            'id_menu' => $id, 'nama_menu' => $nama, 'path' => $path,
            'id_menu_induk' => $idInduk, 'icon' => 'truck', 'urutan' => 1,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_menu_bisa_disembunyikan_dari_sidebar_tanpa_dinonaktifkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idMenu = $this->buatMenu(null, '/menu-tersembunyi', 'Menu Tersembunyi');

        $this->getJson("/api/menu/{$idMenu}")->assertStatus(200)->assertJsonPath('data.tampil_sidebar', true);
        $this->putJson("/api/menu/{$idMenu}", ['tampil_sidebar' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.tampil_sidebar', false)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('menu', ['id_menu' => $idMenu, 'tampil_sidebar' => 0, 'aktif' => 1]);

        $pohon = collect($this->getJson('/api/menu/tree')->assertStatus(200)->json('data'));
        $this->assertFalse($pohon->firstWhere('id_menu', $idMenu)['tampil_sidebar']);
        $this->assertNotNull(collect($this->getJson('/api/menu?limit=999')->json('data'))->firstWhere('id_menu', $idMenu));
    }

    public function test_menu_baru_tampil_di_sidebar_secara_bawaan_dan_menu_pembelian_sparepart_tersembunyi(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/menu', ['nama_menu' => 'Menu Uji', 'path' => '/menu-uji'])
            ->assertStatus(201)
            ->assertJsonPath('data.tampil_sidebar', true);
        $this->postJson('/api/menu', ['nama_menu' => 'Menu Uji Dua', 'path' => '/menu-uji-dua', 'tampil_sidebar' => false])
            ->assertStatus(201)
            ->assertJsonPath('data.tampil_sidebar', false);

        $lama = DB::table('menu')->where('path', '/pembelian-sparepart')->first();
        if ($lama !== null) {
            $this->assertSame(0, (int) $lama->tampil_sidebar);
        }
    }

    public function test_tolak_induk_ber_path_saat_create(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $indukBerPath = $this->buatMenu(null, '/menu-berpath', 'Menu Berpath');

        $res = $this->postJson('/api/menu', [
            'nama_menu'     => 'Anak Salah',
            'path'          => '/anak-salah',
            'id_menu_induk' => $indukBerPath,
        ]);

        $res->assertStatus(422)->assertJsonPath('message', 'Menu induk harus berupa grup (tanpa path)');
    }

    public function test_terima_induk_grup_saat_create(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $indukGrup = $this->buatMenu(null, null, 'Grup Benar');

        $res = $this->postJson('/api/menu', [
            'nama_menu'     => 'Anak Benar',
            'path'          => '/anak-benar',
            'id_menu_induk' => $indukGrup,
        ]);

        $res->assertStatus(201);
    }

    public function test_tolak_induk_ber_path_saat_update(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $indukBerPath = $this->buatMenu(null, '/menu-berpath-2', 'Menu Berpath Dua');
        $menu = $this->buatMenu(null, '/menu-biasa', 'Menu Biasa');

        $res = $this->putJson("/api/menu/{$menu}", [
            'id_menu_induk' => $indukBerPath,
        ]);

        $res->assertStatus(422)->assertJsonPath('message', 'Menu induk harus berupa grup (tanpa path)');
    }
}
