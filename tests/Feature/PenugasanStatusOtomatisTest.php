<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\SupirVendor\SupirVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Penugasan otomatis naik dari 'pending' ke 'aktif' begitu ada supir yang
 * bertanggung jawab (id_supir internal, atau id_supir_vendor/id_supir untuk
 * penugasan vendor). Armada dan tanggal_tugas SENGAJA bukan syarat — supir
 * shift tidak pernah punya armada langsung di penugasan (unitnya ditentukan
 * harian lewat alokasi armada / Papan Jadwal), jadi mensyaratkan id_armada
 * akan membuat penugasan supir shift nyangkut pending selamanya.
 */
class PenugasanStatusOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Status Otomatis Test',
        ]);
    }

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . Str::random(3),
            'merk'          => 'Hino',
            'status'        => 'tersedia',
        ]);
    }

    private function makeSupir(): string
    {
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $idSupir,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Status Otomatis Test',
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);
        return $idSupir;
    }

    public function test_create_dengan_armada_dan_supir_otomatis_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();
        $idSupir = $this->makeSupir();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'id_supir'  => $idSupir,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'aktif');
    }

    public function test_create_hanya_armada_tanpa_supir_tetap_pending(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'pending');
    }

    public function test_create_hanya_supir_tanpa_armada_otomatis_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $idSupir,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'aktif');
    }

    public function test_create_tanpa_armada_dan_supir_tetap_pending(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'pending');
    }

    public function test_create_status_pending_eksplisit_tetap_dipaksa_aktif_bila_pasangan_lengkap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();
        $idSupir = $this->makeSupir();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'id_supir'  => $idSupir,
            'status'    => 'pending',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'aktif');
    }

    public function test_create_status_batal_eksplisit_tidak_ditimpa_meski_pasangan_lengkap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();
        $idSupir = $this->makeSupir();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'id_supir'  => $idSupir,
            'status'    => 'batal',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'batal');
    }

    public function test_update_melengkapi_supir_membuat_status_otomatis_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir();

        $create = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
        ]);
        $create->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $id = $create->json('data.id_penugasan');

        $res = $this->putJson("/api/penugasan/{$id}", [
            'id_supir' => $idSupir,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.status', 'aktif');
    }

    public function test_update_melengkapi_armada_saja_tanpa_supir_tetap_pending(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();

        $create = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
        ]);
        $id = $create->json('data.id_penugasan');

        $res = $this->putJson("/api/penugasan/{$id}", [
            'id_armada' => $armada->id_armada,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.status', 'pending');
    }

    public function test_update_field_lain_tidak_menurunkan_status_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();
        $idSupir = $this->makeSupir();

        $create = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'id_supir'  => $idSupir,
        ]);
        $create->assertStatus(201)->assertJsonPath('data.status', 'aktif');
        $id = $create->json('data.id_penugasan');

        $res = $this->putJson("/api/penugasan/{$id}", [
            'estimasi_biaya' => 150000,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.estimasi_biaya', 150000);
    }

    public function test_update_eksplisit_ke_pending_dengan_pasangan_lengkap_dipaksa_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();
        $idSupir = $this->makeSupir();

        $create = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'id_supir'  => $idSupir,
        ]);
        $id = $create->json('data.id_penugasan');

        $res = $this->putJson("/api/penugasan/{$id}", [
            'status' => 'pending',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.status', 'aktif');
    }

    public function test_create_vendor_mekanisme_full_pasangan_lengkap_otomatis_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $vendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Status Otomatis Test',
        ]);
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'full',
        ]);
        $armadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $vendor->id_vendor,
            'nopol'     => 'B ' . random_int(1000, 9999) . ' VD',
        ]);
        $supirVendor = SupirVendorModel::create([
            'id_vendor' => $vendor->id_vendor,
            'nama'      => 'Supir Vendor Status Otomatis Test',
        ]);

        $res = $this->postJson('/api/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'aktif');
    }

    public function test_create_vendor_unit_only_dengan_supir_internal_otomatis_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $vendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Status Otomatis Test 2',
        ]);
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
        ]);
        $armadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $vendor->id_vendor,
            'nopol'     => 'B ' . random_int(1000, 9999) . ' VD',
        ]);
        $idSupir = $this->makeSupir();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir'          => $idSupir,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'aktif');
    }

}
