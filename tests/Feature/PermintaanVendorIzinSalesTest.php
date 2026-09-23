<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermintaanVendorIzinSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_bisa_melihat_daftar_permintaan_vendor(): void
    {
        $this->actingAsRole('SALES');

        $this->getJson('/api/permintaan-vendor')->assertStatus(200);
    }

    public function test_sales_bisa_membuat_permintaan_vendor(): void
    {
        $this->actingAsRole('SALES');

        $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'catatan'   => 'Unit engkel habis di Ketersediaan Unit',
        ])->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }

    public function test_sales_tetap_tidak_bisa_menghapus_permintaan_vendor(): void
    {
        $this->actingAsRole('SALES');

        $this->deleteJson('/api/permintaan-vendor/' . (string) \Illuminate\Support\Str::uuid())->assertStatus(403);
    }

    public function test_menu_permintaan_vendor_tampil_untuk_sales(): void
    {
        $adaMenuPeran = DB::table('menu_peran')
            ->where('id_menu', 'm0000001-0000-4000-8000-000000000096')
            ->where('kode_peran', 'SALES')
            ->exists();

        $this->assertTrue($adaMenuPeran);
    }
}
