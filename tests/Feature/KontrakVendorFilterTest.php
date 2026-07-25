<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakVendorFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test',
        ]);
    }

    private function makeKontrakVendor(string $idVendor, string $mekanisme = 'unit_only'): KontrakVendorModel
    {
        return KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => $mekanisme,
        ]);
    }

    public function test_index_kontrak_vendor_memfilter_berdasarkan_id_vendor(): void
    {
        $this->actingAsRole('ADMIN');

        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $this->makeKontrakVendor($vendorA->id_vendor);
        $this->makeKontrakVendor($vendorB->id_vendor);

        // Tanpa filter: kedua kontrak vendor muncul.
        $resAll = $this->getJson('/api/v1/kontrak-vendor');
        $resAll->assertStatus(200);
        $this->assertSame(2, $resAll->json('meta.total'));

        // Dengan filter id_vendor: hanya kontrak milik vendor A.
        $resFiltered = $this->getJson('/api/v1/kontrak-vendor?id_vendor=' . $vendorA->id_vendor);
        $resFiltered->assertStatus(200);
        $dataFiltered = $resFiltered->json('data');
        $this->assertCount(1, $dataFiltered);
        $this->assertSame(1, $resFiltered->json('meta.total'));
        $this->assertSame($vendorA->id_vendor, $dataFiltered[0]['id_vendor']);
    }

    public function test_index_kontrak_vendor_memfilter_berdasarkan_search_teks_bebas(): void
    {
        $this->actingAsRole('ADMIN');

        $vendorA = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Cahaya Abadi',
        ]);
        $vendorB = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Mitra Jaya',
        ]);
        $this->makeKontrakVendor($vendorA->id_vendor, 'unit_only');
        $this->makeKontrakVendor($vendorB->id_vendor, 'full');

        // Search berdasarkan nama vendor (join), meski data ada di "halaman lain".
        $resNama = $this->getJson('/api/v1/kontrak-vendor?search=Cahaya&limit=1');
        $resNama->assertStatus(200);
        $dataNama = $resNama->json('data');
        $this->assertSame(1, $resNama->json('meta.total'));
        $this->assertSame($vendorA->id_vendor, $dataNama[0]['id_vendor']);

        // Search berdasarkan mekanisme.
        $resMekanisme = $this->getJson('/api/v1/kontrak-vendor?search=full');
        $resMekanisme->assertStatus(200);
        $dataMekanisme = $resMekanisme->json('data');
        $this->assertSame(1, $resMekanisme->json('meta.total'));
        $this->assertSame($vendorB->id_vendor, $dataMekanisme[0]['id_vendor']);

        // Search tanpa hasil.
        $resKosong = $this->getJson('/api/v1/kontrak-vendor?search=tidak-ada-begini');
        $resKosong->assertStatus(200);
        $this->assertSame(0, $resKosong->json('meta.total'));
    }
}
