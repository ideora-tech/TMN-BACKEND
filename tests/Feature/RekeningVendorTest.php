<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RekeningVendorTest extends TestCase
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

    private function makeVendorPerusahaanLain(): VendorModel
    {
        $idPerusahaanLain = (string) Str::uuid();

        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        return $this->makeVendor($idPerusahaanLain);
    }

    private function insertRekening(string $idVendor, string $namaBank = 'BCA'): string
    {
        $id = (string) Str::uuid();
        DB::table('rekening_vendor')->insert([
            'id_rekening_vendor' => $id,
            'id_vendor'          => $idVendor,
            'nama_bank'          => $namaBank,
            'nomor_rekening'     => '1234567890',
            'atas_nama'          => 'PT Vendor Test',
            'mata_uang'          => 'IDR',
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    public function test_membuat_rekening_vendor_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson("/api/vendor/{$vendor->id_vendor}/rekening", [
            'nama_bank'      => 'BCA',
            'nomor_rekening' => '8888999900',
            'atas_nama'      => 'PT Vendor Test',
            'cabang'         => 'Jakarta Pusat',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_bank', 'BCA')
            ->assertJsonPath('data.nomor_rekening', '8888999900')
            ->assertJsonPath('data.id_vendor', $vendor->id_vendor)
            ->assertJsonPath('data.mata_uang', 'IDR');

        $this->assertDatabaseHas('rekening_vendor', [
            'id_vendor'      => $vendor->id_vendor,
            'nama_bank'      => 'BCA',
            'nomor_rekening' => '8888999900',
            'mata_uang'      => 'IDR',
        ]);
    }

    public function test_menolak_membuat_rekening_tanpa_nama_bank(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson("/api/vendor/{$vendor->id_vendor}/rekening", [
            'nomor_rekening' => '8888999900',
            'atas_nama'      => 'PT Vendor Test',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['nama_bank']);
    }

    public function test_menolak_membuat_rekening_untuk_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();

        $res = $this->postJson("/api/vendor/{$vendorLain->id_vendor}/rekening", [
            'nama_bank'      => 'BCA',
            'nomor_rekening' => '7777888899',
            'atas_nama'      => 'PT Vendor Lain',
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseMissing('rekening_vendor', [
            'nomor_rekening' => '7777888899',
        ]);
    }

    public function test_list_rekening_vendor_by_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $this->insertRekening($vendor->id_vendor, 'Mandiri');
        $this->insertRekening($vendorB->id_vendor, 'BNI');

        $res = $this->getJson("/api/vendor/{$vendor->id_vendor}/rekening");

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Mandiri', $res->json('data.0.nama_bank'));
        $this->assertArrayHasKey('meta', $res->json());
    }

    public function test_list_rekening_vendor_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();
        $this->insertRekening($vendorLain->id_vendor);

        $this->getJson("/api/vendor/{$vendorLain->id_vendor}/rekening")
            ->assertStatus(404);
    }

    public function test_update_rekening_vendor_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertRekening($vendor->id_vendor);

        $res = $this->putJson("/api/vendor/{$vendor->id_vendor}/rekening/{$id}", [
            'nomor_rekening' => '0011223344',
            'cabang'         => 'Surabaya',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nomor_rekening', '0011223344')
            ->assertJsonPath('data.cabang', 'Surabaya');

        $this->assertDatabaseHas('rekening_vendor', [
            'id_rekening_vendor' => $id,
            'nomor_rekening'     => '0011223344',
            'cabang'             => 'Surabaya',
        ]);
    }

    public function test_menolak_update_rekening_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();
        $id = $this->insertRekening($vendorLain->id_vendor);

        $res = $this->putJson("/api/vendor/{$vendorLain->id_vendor}/rekening/{$id}", [
            'nomor_rekening' => '5555666677',
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseHas('rekening_vendor', [
            'id_rekening_vendor' => $id,
            'nomor_rekening'     => '1234567890',
        ]);
    }

    public function test_menolak_update_rekening_milik_vendor_berbeda(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $id = $this->insertRekening($vendorA->id_vendor);

        $res = $this->putJson("/api/vendor/{$vendorB->id_vendor}/rekening/{$id}", [
            'nomor_rekening' => '5555666677',
        ]);

        $res->assertStatus(404);
    }

    public function test_soft_delete_rekening_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = $this->insertRekening($vendor->id_vendor);

        $res = $this->deleteJson("/api/vendor/{$vendor->id_vendor}/rekening/{$id}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('rekening_vendor')->where('id_rekening_vendor', $id)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson("/api/vendor/{$vendor->id_vendor}/rekening")->json('data'));
    }

    public function test_menolak_hapus_rekening_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();
        $id = $this->insertRekening($vendorLain->id_vendor);

        $this->deleteJson("/api/vendor/{$vendorLain->id_vendor}/rekening/{$id}")
            ->assertStatus(404);

        $row = DB::table('rekening_vendor')->where('id_rekening_vendor', $id)->first();
        $this->assertNull($row->dihapus_pada);
    }
}
