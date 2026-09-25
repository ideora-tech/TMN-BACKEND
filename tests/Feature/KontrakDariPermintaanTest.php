<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\PermintaanVendor\PermintaanVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakDariPermintaanTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Permintaan Test',
        ]);
    }

    private function makePermintaan(array $overrides = []): PermintaanVendorModel
    {
        return PermintaanVendorModel::create(array_merge([
            'id_perusahaan'    => self::PERUSAHAAN_ID,
            'nomor_permintaan' => 'PMV-KDP-' . Str::random(6),
            'jumlah_unit'      => 2,
            'mekanisme'        => 'unit_only',
            'status'           => 'disetujui',
        ], $overrides));
    }

    public function test_buat_kontrak_dari_permintaan_disetujui_menautkan_dan_flip_status(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $permintaan = $this->makePermintaan();

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-DARI-PMV-1',
            'id_permintaan' => $permintaan->id_permintaan,
        ]);

        $res->assertStatus(201);
        $idKontrak = $res->json('data.id_kontrak_vendor');

        $segar = $permintaan->fresh();
        $this->assertSame('dikontrakkan', $segar->status);
        $this->assertSame($idKontrak, $segar->id_kontrak_vendor);
    }

    public function test_resource_kontrak_mengekspos_nomor_permintaan_asal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $permintaan = $this->makePermintaan();

        $idKontrak = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-DARI-PMV-2',
            'id_permintaan' => $permintaan->id_permintaan,
        ])->assertStatus(201)->json('data.id_kontrak_vendor');

        $detail = $this->getJson("/api/kontrak-vendor/{$idKontrak}")->assertStatus(200);
        $this->assertSame($permintaan->id_permintaan, $detail->json('data.id_permintaan'));
        $this->assertSame($permintaan->nomor_permintaan, $detail->json('data.nomor_permintaan'));

        $list = $this->getJson('/api/kontrak-vendor?limit=100')->assertStatus(200);
        $baris = collect($list->json('data'))->firstWhere('id_kontrak_vendor', $idKontrak);
        $this->assertNotNull($baris);
        $this->assertSame($permintaan->nomor_permintaan, $baris['nomor_permintaan']);

        $tanpaPermintaan = $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
        ])->assertStatus(201)->json('data.id_kontrak_vendor');
        $this->assertNull($this->getJson("/api/kontrak-vendor/{$tanpaPermintaan}")->json('data.nomor_permintaan'));
    }

    public function test_permintaan_belum_disetujui_422_dan_kontrak_tidak_dibuat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        foreach (['draft', 'menunggu_approval', 'ditolak'] as $status) {
            $permintaan = $this->makePermintaan(['status' => $status]);

            $this->postJson('/api/kontrak-vendor', [
                'id_vendor'     => $vendor->id_vendor,
                'mekanisme'     => 'unit_only',
                'id_permintaan' => $permintaan->id_permintaan,
            ])->assertStatus(422);
        }

        $this->assertDatabaseMissing('kontrak_vendor', ['id_vendor' => $vendor->id_vendor]);
    }

    public function test_permintaan_sudah_dikontrakkan_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $permintaan = $this->makePermintaan([
            'status'            => 'dikontrakkan',
            'id_kontrak_vendor' => (string) Str::uuid(),
        ]);

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'id_permintaan' => $permintaan->id_permintaan,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('disetujui atau diproses', $res->json('message'));
    }

    public function test_permintaan_tenant_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain KDP', 'dibuat_pada' => now(),
        ]);
        $permintaanLain = $this->makePermintaan(['id_perusahaan' => $idPerusahaanLain]);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'id_permintaan' => $permintaanLain->id_permintaan,
        ])->assertStatus(404);

        $this->assertSame('disetujui', $permintaanLain->fresh()->status);
        $this->assertDatabaseMissing('kontrak_vendor', ['id_vendor' => $vendor->id_vendor]);
    }

    public function test_kontrak_tanpa_id_permintaan_tetap_normal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
        ])->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }
}
