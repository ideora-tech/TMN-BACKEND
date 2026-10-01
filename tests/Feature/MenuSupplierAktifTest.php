<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuSupplierAktifTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_SUPPLIER  = 'm0000001-0000-4000-8000-000000000085';
    private const ID_GRUP_PENGADAAN = 'm0000001-0000-4000-8000-000000000098';

    public function test_menu_supplier_aktif_di_grup_pengadaan(): void
    {
        $menu = DB::table('menu')->where('id_menu', self::ID_MENU_SUPPLIER)->first();

        $this->assertNotNull($menu);
        $this->assertSame(1, (int) $menu->aktif);
        $this->assertSame(self::ID_GRUP_PENGADAAN, $menu->id_menu_induk);
    }

    public function test_menu_supplier_tersedia_di_matriks_izin_peran(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $baris = collect($this->getJson('/api/menu?page=1&limit=200')->assertStatus(200)->json('data'))
            ->firstWhere('path', '/supplier');

        $this->assertNotNull($baris);
        $this->assertTrue($baris['aktif']);
        $this->assertSame(self::ID_GRUP_PENGADAAN, $baris['id_menu_induk']);
    }

    public function test_menu_supplier_tampil_di_sidebar_peran_dengan_izin_lihat(): void
    {
        $this->actingAsRole('PENGADAAN');

        $this->assertStringContainsString('/supplier', (string) $this->getJson('/api/menu/tree')->assertStatus(200)->getContent());
    }
}
