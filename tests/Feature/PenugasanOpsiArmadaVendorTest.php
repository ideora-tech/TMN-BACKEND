<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenugasanOpsiArmadaVendorTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Opsi Armada Vendor',
        ]);
    }

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Opsi Armada',
        ]);
    }

    private function makeKontrak(string $idVendor, string $mekanisme, ?string $idPerusahaan = null): KontrakVendorModel
    {
        return KontrakVendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => $mekanisme,
        ]);
    }

    private function makeArmadaVendor(string $idVendor, array $override = []): ArmadaVendorModel
    {
        return ArmadaVendorModel::create(array_merge([
            'id_vendor' => $idVendor,
            'nopol'     => 'B ' . random_int(1000, 9999) . ' VD',
        ], $override));
    }

    public function test_opsi_hanya_unit_dengan_kontrak_unit_only(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorA = $this->makeVendor();
        $this->makeKontrak($vendorA->id_vendor, 'unit_only');
        $unitA = $this->makeArmadaVendor($vendorA->id_vendor);

        $vendorB = $this->makeVendor();
        $this->makeKontrak($vendorB->id_vendor, 'unit_driver');
        $this->makeArmadaVendor($vendorB->id_vendor);

        $res = $this->getJson('/api/penugasan/opsi-armada-vendor');
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($unitA->id_armada_vendor, $data[0]['id_armada_vendor']);
        $this->assertSame($vendorA->nama_vendor, $data[0]['nama_vendor']);
        $this->assertNotEmpty($data[0]['id_kontrak_vendor']);
    }

    public function test_opsi_mengabaikan_unit_nonaktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $this->makeArmadaVendor($vendor->id_vendor, ['aktif' => 0]);

        $res = $this->getJson('/api/penugasan/opsi-armada-vendor');
        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_opsi_scoped_ke_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Opsi Armada',
            'dibuat_pada'   => now(),
        ]);
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $this->makeKontrak($vendorLain->id_vendor, 'unit_only', $idPerusahaanLain);
        $this->makeArmadaVendor($vendorLain->id_vendor);

        $res = $this->getJson('/api/penugasan/opsi-armada-vendor');
        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_opsi_unit_tanpa_kontrak_tidak_muncul(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $this->makeArmadaVendor($vendor->id_vendor);

        $res = $this->getJson('/api/penugasan/opsi-armada-vendor');
        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_filter_sumber_operasional_mengembalikan_internal_dan_vendor_unit_only_saja(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrakUnitOnly = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $kontrakUnitDriver = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $unitA = $this->makeArmadaVendor($vendor->id_vendor);
        $unitB = $this->makeArmadaVendor($vendor->id_vendor);

        $internal = PenugasanModel::create(['id_proyek' => $proyek->id_proyek]);

        $unitOnlyRow = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrakUnitOnly->id_kontrak_vendor,
            'id_armada_vendor'  => $unitA->id_armada_vendor,
            'id_supir'          => (string) Str::uuid(),
        ]);

        PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrakUnitDriver->id_kontrak_vendor,
            'id_armada_vendor'  => $unitB->id_armada_vendor,
            'id_supir_vendor'   => (string) Str::uuid(),
        ]);

        $res = $this->getJson('/api/penugasan?id_proyek=' . $proyek->id_proyek . '&sumber=operasional');
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id_penugasan')->all();
        $this->assertCount(2, $ids);
        $this->assertContains($internal->id_penugasan, $ids);
        $this->assertContains($unitOnlyRow->id_penugasan, $ids);
    }
}
