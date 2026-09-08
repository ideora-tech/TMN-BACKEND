<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakPayungTest extends TestCase
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

    private function makeKontrak(VendorModel $vendor, array $override = []): KontrakVendorModel
    {
        return KontrakVendorModel::create(array_merge([
            'id_perusahaan' => $vendor->id_perusahaan,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
        ], $override));
    }

    public function test_tautan_induk_valid_tersimpan_saat_create(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $induk  = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-INDUK-1']);

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'        => $vendor->id_vendor,
            'mekanisme'        => 'unit_only',
            'nomor_kontrak'    => 'KV-TURUNAN-1',
            'id_kontrak_induk' => $induk->id_kontrak_vendor,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_kontrak_induk', $induk->id_kontrak_vendor);

        $this->assertDatabaseHas('kontrak_vendor', [
            'nomor_kontrak'    => 'KV-TURUNAN-1',
            'id_kontrak_induk' => $induk->id_kontrak_vendor,
        ]);
    }

    public function test_tautan_induk_valid_tersimpan_saat_update(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $induk   = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-INDUK-2']);
        $turunan = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-TURUNAN-2']);

        $res = $this->putJson("/api/kontrak-vendor/{$turunan->id_kontrak_vendor}", [
            'id_kontrak_induk' => $induk->id_kontrak_vendor,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.id_kontrak_induk', $induk->id_kontrak_vendor);

        $this->assertDatabaseHas('kontrak_vendor', [
            'id_kontrak_vendor' => $turunan->id_kontrak_vendor,
            'id_kontrak_induk'  => $induk->id_kontrak_vendor,
        ]);
    }

    public function test_kontrak_payung_beda_vendor_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $induk   = $this->makeKontrak($vendorA, ['nomor_kontrak' => 'KV-INDUK-3']);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'        => $vendorB->id_vendor,
            'mekanisme'        => 'unit_only',
            'nomor_kontrak'    => 'KV-TURUNAN-3',
            'id_kontrak_induk' => $induk->id_kontrak_vendor,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('kontrak_vendor', [
            'nomor_kontrak' => 'KV-TURUNAN-3',
        ]);
    }

    public function test_kontrak_payung_bertingkat_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $induk  = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-INDUK-4']);
        $anak   = $this->makeKontrak($vendor, [
            'nomor_kontrak'    => 'KV-ANAK-4',
            'id_kontrak_induk' => $induk->id_kontrak_vendor,
        ]);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'        => $vendor->id_vendor,
            'mekanisme'        => 'unit_only',
            'nomor_kontrak'    => 'KV-CUCU-4',
            'id_kontrak_induk' => $anak->id_kontrak_vendor,
        ])->assertStatus(422);
    }

    public function test_kontrak_induk_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'        => $vendor->id_vendor,
            'mekanisme'        => 'unit_only',
            'nomor_kontrak'    => 'KV-INDUK-HANTU',
            'id_kontrak_induk' => (string) Str::uuid(),
        ])->assertStatus(404);
    }

    public function test_kontrak_induk_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $indukLain = $this->makeKontrak($this->makeVendorPerusahaanLain());

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'        => $vendor->id_vendor,
            'mekanisme'        => 'unit_only',
            'nomor_kontrak'    => 'KV-INDUK-LAIN',
            'id_kontrak_induk' => $indukLain->id_kontrak_vendor,
        ])->assertStatus(404);
    }

    public function test_jadikan_turunan_kontrak_yang_sudah_ber_turunan_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $payung = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-PAYUNG-5']);
        $this->makeKontrak($vendor, [
            'nomor_kontrak'    => 'KV-ANAK-5',
            'id_kontrak_induk' => $payung->id_kontrak_vendor,
        ]);
        $indukLain = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-INDUK-LAIN-5']);

        $this->putJson("/api/kontrak-vendor/{$payung->id_kontrak_vendor}", [
            'id_kontrak_induk' => $indukLain->id_kontrak_vendor,
        ])->assertStatus(422);

        $this->assertDatabaseHas('kontrak_vendor', [
            'id_kontrak_vendor' => $payung->id_kontrak_vendor,
            'id_kontrak_induk'  => null,
        ]);
    }

    public function test_self_induk_saat_update_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-SELF-6']);

        $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'id_kontrak_induk' => $kontrak->id_kontrak_vendor,
        ])->assertStatus(422);
    }

    public function test_resource_list_dan_detail_mengekspos_field_payung_dengan_benar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $payung = $this->makeKontrak($vendor, [
            'nomor_kontrak' => 'KV-PAYUNG-7',
            'nilai_kontrak' => 1000000,
        ]);
        $turunan1 = $this->makeKontrak($vendor, [
            'nomor_kontrak'    => 'KV-TURUNAN-7A',
            'id_kontrak_induk' => $payung->id_kontrak_vendor,
            'nilai_kontrak'    => 600000,
            'status'           => 'aktif',
        ]);
        $turunan2 = $this->makeKontrak($vendor, [
            'nomor_kontrak'    => 'KV-TURUNAN-7B',
            'id_kontrak_induk' => $payung->id_kontrak_vendor,
            'nilai_kontrak'    => 700000,
            'status'           => 'draft',
        ]);

        $detail = $this->getJson("/api/kontrak-vendor/{$payung->id_kontrak_vendor}")
            ->assertStatus(200)
            ->assertJsonPath('data.id_kontrak_induk', null)
            ->assertJsonPath('data.nomor_kontrak_induk', null)
            ->assertJsonPath('data.jumlah_turunan', 2)
            ->assertJsonPath('data.total_nilai_turunan', 1300000)
            ->assertJsonCount(2, 'data.turunan');

        $nomorTurunan = collect($detail->json('data.turunan'))->pluck('nomor_kontrak')->all();
        $this->assertContains('KV-TURUNAN-7A', $nomorTurunan);
        $this->assertContains('KV-TURUNAN-7B', $nomorTurunan);

        $baris = collect($detail->json('data.turunan'))->firstWhere('nomor_kontrak', 'KV-TURUNAN-7A');
        $this->assertSame($turunan1->id_kontrak_vendor, $baris['id_kontrak_vendor']);
        $this->assertSame('unit_only', $baris['mekanisme']);
        $this->assertSame(600000, $baris['nilai_kontrak']);
        $this->assertSame('aktif', $baris['status']);

        $list = $this->getJson('/api/kontrak-vendor?limit=50')->assertStatus(200);
        $entriPayung = collect($list->json('data'))->firstWhere('id_kontrak_vendor', $payung->id_kontrak_vendor);
        $this->assertSame(2, $entriPayung['jumlah_turunan']);
        $this->assertNull($entriPayung['nomor_kontrak_induk']);
        $this->assertArrayNotHasKey('turunan', $entriPayung);

        $entriTurunan = collect($list->json('data'))->firstWhere('id_kontrak_vendor', $turunan1->id_kontrak_vendor);
        $this->assertSame('KV-PAYUNG-7', $entriTurunan['nomor_kontrak_induk']);
        $this->assertSame(0, $entriTurunan['jumlah_turunan']);
    }

    public function test_hapus_kontrak_payung_ber_turunan_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $payung = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-PAYUNG-8']);
        $this->makeKontrak($vendor, [
            'nomor_kontrak'    => 'KV-ANAK-8',
            'id_kontrak_induk' => $payung->id_kontrak_vendor,
        ]);

        $this->deleteJson("/api/kontrak-vendor/{$payung->id_kontrak_vendor}")
            ->assertStatus(422);

        $this->assertDatabaseHas('kontrak_vendor', [
            'id_kontrak_vendor' => $payung->id_kontrak_vendor,
            'dihapus_pada'      => null,
        ]);
    }

    public function test_hapus_kontrak_turunan_biasa_tetap_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $payung = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-PAYUNG-9']);
        $turunan = $this->makeKontrak($vendor, [
            'nomor_kontrak'    => 'KV-ANAK-9',
            'id_kontrak_induk' => $payung->id_kontrak_vendor,
        ]);

        $this->deleteJson("/api/kontrak-vendor/{$turunan->id_kontrak_vendor}")
            ->assertStatus(200);

        $row = DB::table('kontrak_vendor')
            ->where('id_kontrak_vendor', $turunan->id_kontrak_vendor)
            ->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_melepas_id_kontrak_induk_dengan_null_tidak_perlu_validasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $payung  = $this->makeKontrak($vendor, ['nomor_kontrak' => 'KV-PAYUNG-10']);
        $turunan = $this->makeKontrak($vendor, [
            'nomor_kontrak'    => 'KV-ANAK-10',
            'id_kontrak_induk' => $payung->id_kontrak_vendor,
        ]);

        $this->putJson("/api/kontrak-vendor/{$turunan->id_kontrak_vendor}", [
            'id_kontrak_induk' => null,
        ])->assertStatus(200)
            ->assertJsonPath('data.id_kontrak_induk', null);

        $this->assertDatabaseHas('kontrak_vendor', [
            'id_kontrak_vendor' => $turunan->id_kontrak_vendor,
            'id_kontrak_induk'  => null,
        ]);
    }

    public function test_nilai_turunan_boleh_melebihi_plafon_payung_tanpa_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $payung = $this->makeKontrak($vendor, [
            'nomor_kontrak' => 'KV-PAYUNG-11',
            'nilai_kontrak' => 100000,
        ]);

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'        => $vendor->id_vendor,
            'mekanisme'        => 'unit_only',
            'nomor_kontrak'    => 'KV-TURUNAN-11',
            'id_kontrak_induk' => $payung->id_kontrak_vendor,
            'nilai_kontrak'    => 999999999,
        ]);

        $res->assertStatus(201);
    }
}
