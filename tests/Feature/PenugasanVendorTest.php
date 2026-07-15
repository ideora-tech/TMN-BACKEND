<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\SupirVendor\SupirVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenugasanVendorTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(?string $idPerusahaan = null): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Test Penugasan Vendor',
        ]);
    }

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test Penugasan',
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

    private function makeArmadaVendor(string $idVendor): ArmadaVendorModel
    {
        return ArmadaVendorModel::create([
            'id_vendor' => $idVendor,
            'nopol'     => 'B ' . random_int(1000, 9999) . ' VD',
        ]);
    }

    private function makeSupirVendor(string $idVendor): SupirVendorModel
    {
        return SupirVendorModel::create([
            'id_vendor' => $idVendor,
            'nama'      => 'Supir Vendor Test',
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

    public function test_create_vendor_unit_only_lengkap_berhasil_201(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir'          => (string) Str::uuid(),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sumber', 'vendor')
            ->assertJsonPath('data.id_kontrak_vendor', $kontrak->id_kontrak_vendor)
            ->assertJsonPath('data.id_armada_vendor', $armadaVendor->id_armada_vendor);

        $this->assertDatabaseHas('penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'sumber'    => 'vendor',
        ]);
    }

    public function test_create_unit_only_tanpa_supir_internal_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('supir internal', (string) $res->json('message'));
    }

    public function test_create_unit_driver_dengan_supir_internal_terisi_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);
        $supirVendor = $this->makeSupirVendor($vendor->id_vendor);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
            'id_supir'          => (string) Str::uuid(),
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('supir dari vendor', (string) $res->json('message'));
    }

    public function test_create_unit_driver_lengkap_berhasil_201(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);
        $supirVendor = $this->makeSupirVendor($vendor->id_vendor);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_supir_vendor', $supirVendor->id_supir_vendor);

        $this->assertDatabaseHas('penugasan', [
            'id_proyek'       => $proyek->id_proyek,
            'id_supir_vendor' => $supirVendor->id_supir_vendor,
        ]);
    }

    public function test_create_armada_vendor_milik_vendor_lain_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendorA->id_vendor, 'unit_only');
        $armadaVendorLain = $this->makeArmadaVendor($vendorB->id_vendor);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendorLain->id_armada_vendor,
            'id_supir'          => (string) Str::uuid(),
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('tidak sesuai dengan vendor kontrak', (string) $res->json('message'));
    }

    public function test_create_internal_dengan_id_armada_vendor_terisi_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'        => $proyek->id_proyek,
            'id_armada_vendor' => $armadaVendor->id_armada_vendor,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('hanya untuk penugasan bersumber vendor', (string) $res->json('message'));
    }

    public function test_create_kontrak_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();

        $idPerusahaanLain = $this->makePerusahaanLain();
        $vendorLain = $this->makeVendor($idPerusahaanLain);
        $kontrakLain = $this->makeKontrak($vendorLain->id_vendor, 'unit_only', $idPerusahaanLain);
        $armadaVendorLain = $this->makeArmadaVendor($vendorLain->id_vendor);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrakLain->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendorLain->id_armada_vendor,
            'id_supir'          => (string) Str::uuid(),
        ]);

        $res->assertStatus(404);
    }

    public function test_update_unit_only_menjadi_tanpa_supir_internal_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir'          => (string) Str::uuid(),
        ]);

        $res = $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", [
            'id_supir' => null,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('supir internal', (string) $res->json('message'));
    }

    public function test_filter_sumber_vendor_hanya_mengembalikan_baris_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);

        PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
        ]);
        PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir'          => (string) Str::uuid(),
        ]);

        $res = $this->getJson('/api/v1/penugasan?id_proyek=' . $proyek->id_proyek . '&sumber=vendor');
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('vendor', $data[0]['sumber']);

        $resAll = $this->getJson('/api/v1/penugasan?id_proyek=' . $proyek->id_proyek);
        $this->assertCount(2, $resAll->json('data'));
    }

    public function test_penugasan_lama_tanpa_sumber_terbaca_internal_dan_create_tanpa_sumber_tetap_lolos(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();

        $lama = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
        ]);
        $this->assertSame('internal', $lama->fresh()->sumber);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.sumber', 'internal');
    }

    public function test_create_dengan_sumber_null_eksplisit_dianggap_internal(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'sumber'    => null,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.sumber', 'internal');

        $this->assertDatabaseHas('penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'sumber'    => 'internal',
        ]);
    }

    public function test_update_dengan_sumber_null_eksplisit_dianggap_internal(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();

        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
        ]);

        $res = $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", [
            'sumber' => null,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sumber', 'internal');

        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $penugasan->id_penugasan,
            'sumber'       => 'internal',
        ]);
    }

    public function test_update_parsial_penugasan_vendor_tetap_valid(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);
        $supirVendor = $this->makeSupirVendor($vendor->id_vendor);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
        ]);

        $res = $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", [
            'tanggal_tugas' => '2026-08-01',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sumber', 'vendor')
            ->assertJsonPath('data.id_kontrak_vendor', $kontrak->id_kontrak_vendor)
            ->assertJsonPath('data.id_armada_vendor', $armadaVendor->id_armada_vendor)
            ->assertJsonPath('data.id_supir_vendor', $supirVendor->id_supir_vendor);

        $this->assertDatabaseHas('penugasan', [
            'id_penugasan'      => $penugasan->id_penugasan,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
            'tanggal_tugas'     => '2026-08-01',
        ]);
    }

    public function test_update_sumber_vendor_ke_internal_wajib_kosongkan_field_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);
        $supirVendor = $this->makeSupirVendor($vendor->id_vendor);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
        ]);

        $resGagal = $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", [
            'sumber' => 'internal',
        ]);

        $resGagal->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('hanya untuk penugasan bersumber vendor', (string) $resGagal->json('message'));

        $resBerhasil = $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", [
            'sumber'            => 'internal',
            'id_kontrak_vendor' => null,
            'id_armada_vendor'  => null,
            'id_supir_vendor'   => null,
        ]);

        $resBerhasil->assertStatus(200)
            ->assertJsonPath('data.sumber', 'internal')
            ->assertJsonPath('data.id_kontrak_vendor', null)
            ->assertJsonPath('data.id_armada_vendor', null)
            ->assertJsonPath('data.id_supir_vendor', null);

        $this->assertDatabaseHas('penugasan', [
            'id_penugasan'      => $penugasan->id_penugasan,
            'sumber'            => 'internal',
            'id_kontrak_vendor' => null,
            'id_armada_vendor'  => null,
            'id_supir_vendor'   => null,
        ]);
    }

    public function test_create_penugasan_vendor_mekanisme_full(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'full');
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);
        $supirVendor = $this->makeSupirVendor($vendor->id_vendor);

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.sumber', 'vendor')
            ->assertJsonPath('data.id_kontrak_vendor', $kontrak->id_kontrak_vendor)
            ->assertJsonPath('data.id_armada_vendor', $armadaVendor->id_armada_vendor)
            ->assertJsonPath('data.id_supir_vendor', $supirVendor->id_supir_vendor);

        $this->assertDatabaseHas('penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
        ]);
    }
}
