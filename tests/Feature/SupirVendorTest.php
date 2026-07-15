<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\SupirVendor\SupirVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirVendorTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test',
        ]);
    }

    private function makePerusahaanLain(): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        return $idPerusahaanLain;
    }

    private function makeSupirVendor(string $idVendor, string $nama = 'Budi Santoso'): SupirVendorModel
    {
        return SupirVendorModel::create([
            'id_vendor' => $idVendor,
            'nama'      => $nama,
            'telepon'   => '081234567890',
            'no_sim'    => 'SIM-001',
        ]);
    }

    public function test_membuat_supir_vendor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/v1/supir-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'nama'      => 'Andi Wijaya',
            'telepon'   => '081298765432',
            'no_sim'    => 'SIM-999',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama', 'Andi Wijaya')
            ->assertJsonPath('data.id_vendor', $vendor->id_vendor)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('supir_vendor', [
            'id_vendor' => $vendor->id_vendor,
            'nama'      => 'Andi Wijaya',
        ]);
    }

    public function test_menolak_membuat_supir_vendor_dengan_id_vendor_milik_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);

        $res = $this->postJson('/api/v1/supir-vendor', [
            'id_vendor' => $vendorLain->id_vendor,
            'nama'      => 'Coba Curang',
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseMissing('supir_vendor', [
            'nama' => 'Coba Curang',
        ]);
    }

    public function test_list_supir_vendor_hanya_milik_perusahaan_user_dan_filter_id_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $this->makeSupirVendor($vendorA->id_vendor, 'Supir A');
        $this->makeSupirVendor($vendorB->id_vendor, 'Supir B');

        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $this->makeSupirVendor($vendorLain->id_vendor, 'Supir Lain');

        $res = $this->getJson('/api/v1/supir-vendor');
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(2, $res->json('meta.total'));

        $resFiltered = $this->getJson('/api/v1/supir-vendor?id_vendor=' . $vendorA->id_vendor);
        $resFiltered->assertStatus(200);
        $dataFiltered = $resFiltered->json('data');
        $this->assertCount(1, $dataFiltered);
        $this->assertSame('Supir A', $dataFiltered[0]['nama']);
    }

    public function test_show_supir_vendor_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $supirLain = $this->makeSupirVendor($vendorLain->id_vendor);

        $res = $this->getJson("/api/v1/supir-vendor/{$supirLain->id_supir_vendor}");

        $res->assertStatus(404);
    }

    public function test_update_supir_vendor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();
        $supir = $this->makeSupirVendor($vendor->id_vendor);

        $res = $this->putJson("/api/v1/supir-vendor/{$supir->id_supir_vendor}", [
            'nama' => 'Budi Update',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama', 'Budi Update');

        $this->assertDatabaseHas('supir_vendor', [
            'id_supir_vendor' => $supir->id_supir_vendor,
            'nama'            => 'Budi Update',
        ]);
    }

    public function test_update_supir_vendor_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $supirLain = $this->makeSupirVendor($vendorLain->id_vendor);

        $res = $this->putJson("/api/v1/supir-vendor/{$supirLain->id_supir_vendor}", [
            'nama' => 'Coba Update',
        ]);

        $res->assertStatus(404);
    }

    public function test_update_menolak_pindah_ke_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();
        $supir = $this->makeSupirVendor($vendor->id_vendor);

        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);

        $res = $this->putJson("/api/v1/supir-vendor/{$supir->id_supir_vendor}", [
            'id_vendor' => $vendorLain->id_vendor,
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseHas('supir_vendor', [
            'id_supir_vendor' => $supir->id_supir_vendor,
            'id_vendor'       => $vendor->id_vendor,
        ]);
    }

    public function test_hapus_supir_vendor_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();
        $supir = $this->makeSupirVendor($vendor->id_vendor);

        $res = $this->deleteJson("/api/v1/supir-vendor/{$supir->id_supir_vendor}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('supir_vendor')->where('id_supir_vendor', $supir->id_supir_vendor)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson('/api/v1/supir-vendor')->json('data'));
    }

    public function test_hapus_supir_vendor_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $supirLain = $this->makeSupirVendor($vendorLain->id_vendor);

        $res = $this->deleteJson("/api/v1/supir-vendor/{$supirLain->id_supir_vendor}");

        $res->assertStatus(404);
    }
}
