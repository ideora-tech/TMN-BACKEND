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

    public function test_tolak_induk_ber_path_saat_create(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $indukBerPath = $this->buatMenu(null, '/menu-berpath', 'Menu Berpath');

        $res = $this->postJson('/api/v1/menu', [
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

        $res = $this->postJson('/api/v1/menu', [
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

        $res = $this->putJson("/api/v1/menu/{$menu}", [
            'id_menu_induk' => $indukBerPath,
        ]);

        $res->assertStatus(422)->assertJsonPath('message', 'Menu induk harus berupa grup (tanpa path)');
    }
}
