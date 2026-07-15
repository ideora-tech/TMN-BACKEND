<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArmadaVendorTest extends TestCase
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

    private function makeArmadaVendor(string $idVendor, string $nopol = 'B 1234 XY'): ArmadaVendorModel
    {
        return ArmadaVendorModel::create([
            'id_vendor' => $idVendor,
            'nopol'     => $nopol,
            'merk'      => 'Hino',
            'jenis'     => 'Truk',
            'tahun'     => 2020,
        ]);
    }

    public function test_membuat_armada_vendor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/v1/armada-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'nopol'     => 'B 9999 ZZ',
            'merk'      => 'Hino',
            'jenis'     => 'Truk',
            'tahun'     => 2021,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nopol', 'B 9999 ZZ')
            ->assertJsonPath('data.id_vendor', $vendor->id_vendor)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('armada_vendor', [
            'id_vendor' => $vendor->id_vendor,
            'nopol'     => 'B 9999 ZZ',
        ]);
    }

    public function test_menolak_membuat_armada_vendor_dengan_id_vendor_milik_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);

        $res = $this->postJson('/api/v1/armada-vendor', [
            'id_vendor' => $vendorLain->id_vendor,
            'nopol'     => 'B 8888 XX',
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseMissing('armada_vendor', [
            'nopol' => 'B 8888 XX',
        ]);
    }

    public function test_list_armada_vendor_hanya_milik_perusahaan_user_dan_filter_id_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $this->makeArmadaVendor($vendorA->id_vendor, 'B 1111 AA');
        $this->makeArmadaVendor($vendorB->id_vendor, 'B 2222 BB');

        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $this->makeArmadaVendor($vendorLain->id_vendor, 'B 3333 CC');

        $res = $this->getJson('/api/v1/armada-vendor');
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(2, $res->json('meta.total'));

        $resFiltered = $this->getJson('/api/v1/armada-vendor?id_vendor=' . $vendorA->id_vendor);
        $resFiltered->assertStatus(200);
        $dataFiltered = $resFiltered->json('data');
        $this->assertCount(1, $dataFiltered);
        $this->assertSame('B 1111 AA', $dataFiltered[0]['nopol']);
    }

    public function test_show_armada_vendor_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $armadaLain = $this->makeArmadaVendor($vendorLain->id_vendor);

        $res = $this->getJson("/api/v1/armada-vendor/{$armadaLain->id_armada_vendor}");

        $res->assertStatus(404);
    }

    public function test_update_armada_vendor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();
        $armada = $this->makeArmadaVendor($vendor->id_vendor);

        $res = $this->putJson("/api/v1/armada-vendor/{$armada->id_armada_vendor}", [
            'nopol' => 'B 5555 UP',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nopol', 'B 5555 UP');

        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor' => $armada->id_armada_vendor,
            'nopol'            => 'B 5555 UP',
        ]);
    }

    public function test_update_armada_vendor_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $armadaLain = $this->makeArmadaVendor($vendorLain->id_vendor);

        $res = $this->putJson("/api/v1/armada-vendor/{$armadaLain->id_armada_vendor}", [
            'nopol' => 'B 6666 UP',
        ]);

        $res->assertStatus(404);
    }

    public function test_update_menolak_pindah_ke_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();
        $armada = $this->makeArmadaVendor($vendor->id_vendor);

        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);

        $res = $this->putJson("/api/v1/armada-vendor/{$armada->id_armada_vendor}", [
            'id_vendor' => $vendorLain->id_vendor,
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor' => $armada->id_armada_vendor,
            'id_vendor'        => $vendor->id_vendor,
        ]);
    }

    public function test_hapus_armada_vendor_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();
        $armada = $this->makeArmadaVendor($vendor->id_vendor);

        $res = $this->deleteJson("/api/v1/armada-vendor/{$armada->id_armada_vendor}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('armada_vendor')->where('id_armada_vendor', $armada->id_armada_vendor)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson('/api/v1/armada-vendor')->json('data'));
    }

    public function test_hapus_armada_vendor_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $armadaLain = $this->makeArmadaVendor($vendorLain->id_vendor);

        $res = $this->deleteJson("/api/v1/armada-vendor/{$armadaLain->id_armada_vendor}");

        $res->assertStatus(404);
    }
}
