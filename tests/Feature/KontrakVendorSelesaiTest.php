<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakVendorSelesaiTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Selesai Test',
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

    private function makeKontrak(VendorModel $vendor, string $status = 'aktif', array $extra = []): KontrakVendorModel
    {
        return KontrakVendorModel::create(array_merge([
            'id_perusahaan' => $vendor->id_perusahaan,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-SEL-' . Str::random(5),
            'status'        => $status,
        ], $extra));
    }

    public function test_selesaikan_kontrak_aktif_berhasil_dan_mengisi_tanggal_selesai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrak = $this->makeKontrak($this->makeVendor());

        $res = $this->patchJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/selesai");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.tanggal_selesai', now()->toDateString());

        $this->assertDatabaseHas('kontrak_vendor', [
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'status'            => 'selesai',
            'tanggal_selesai'   => now()->toDateString(),
        ]);
    }

    public function test_selesaikan_memajukan_tanggal_selesai_yang_masih_di_masa_depan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrak = $this->makeKontrak($this->makeVendor(), 'aktif', [
            'tanggal_mulai'   => now()->subDays(10)->toDateString(),
            'tanggal_selesai' => now()->addDays(30)->toDateString(),
        ]);

        $this->patchJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/selesai")
            ->assertStatus(200)
            ->assertJsonPath('data.tanggal_selesai', now()->toDateString());
    }

    public function test_selesaikan_membiarkan_tanggal_selesai_yang_sudah_lewat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $tanggalLalu = now()->subDays(5)->toDateString();
        $kontrak = $this->makeKontrak($this->makeVendor(), 'aktif', [
            'tanggal_mulai'   => now()->subDays(40)->toDateString(),
            'tanggal_selesai' => $tanggalLalu,
        ]);

        $this->patchJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/selesai")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.tanggal_selesai', $tanggalLalu);
    }

    public function test_selesaikan_kontrak_draft_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrak = $this->makeKontrak($this->makeVendor(), 'draft');

        $this->patchJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/selesai")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya kontrak berstatus aktif yang bisa diselesaikan');

        $this->assertSame('draft', $kontrak->fresh()->status);
    }

    public function test_selesaikan_kontrak_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrakLain = $this->makeKontrak($this->makeVendorPerusahaanLain());

        $this->patchJson("/api/kontrak-vendor/{$kontrakLain->id_kontrak_vendor}/selesai")
            ->assertStatus(404);

        $this->assertSame('aktif', $kontrakLain->fresh()->status);
    }

    public function test_update_kontrak_yang_sudah_selesai_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrak = $this->makeKontrak($this->makeVendor());

        $this->patchJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/selesai")->assertStatus(200);

        $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'nomor_kontrak' => 'KV-UBAH-SETELAH-SELESAI',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Kontrak yang sudah selesai tidak bisa diubah');

        $this->assertDatabaseMissing('kontrak_vendor', [
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'nomor_kontrak'     => 'KV-UBAH-SETELAH-SELESAI',
        ]);
    }

    public function test_selesaikan_ditolak_bila_ada_penugasan_hidup(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrak = $this->makeKontrak($this->makeVendor());
        DB::table('penugasan')->insert([
            'id_penugasan'      => (string) Str::uuid(),
            'id_proyek'         => (string) Str::uuid(),
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'sumber'            => 'vendor',
            'status'            => 'aktif',
            'dibuat_pada'       => now(),
        ]);

        $this->patchJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/selesai")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Masih ada penugasan aktif pada unit kontrak ini, selesaikan atau hapus penugasannya dulu');

        $this->assertSame('aktif', $kontrak->fresh()->status);
    }

    public function test_selesaikan_boleh_bila_penugasannya_sudah_selesai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrak = $this->makeKontrak($this->makeVendor());
        DB::table('penugasan')->insert([
            'id_penugasan'      => (string) Str::uuid(),
            'id_proyek'         => (string) Str::uuid(),
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'sumber'            => 'vendor',
            'status'            => 'selesai',
            'dibuat_pada'       => now(),
        ]);

        $this->patchJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/selesai")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai');
    }

    public function test_create_dengan_rate_melebihi_nilai_kontrak_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nilai_kontrak' => 1000000,
            'rate'          => 1500000,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Rate tidak boleh lebih besar dari nilai kontrak');

        $this->assertDatabaseMissing('kontrak_vendor', ['id_vendor' => $vendor->id_vendor]);
    }

    public function test_create_dengan_rate_sama_dengan_nilai_kontrak_diterima(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nilai_kontrak' => 1000000,
            'rate'          => 1000000,
        ])->assertStatus(201)
            ->assertJsonPath('data.rate', 1000000);
    }

    public function test_create_dengan_rate_tanpa_nilai_kontrak_tetap_diterima(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
            'rate'      => 250000,
        ])->assertStatus(201)
            ->assertJsonPath('data.nilai_kontrak', 0);
    }

    public function test_update_rate_melebihi_nilai_kontrak_lama_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrak = $this->makeKontrak($this->makeVendor(), 'draft', ['nilai_kontrak' => 500000]);

        $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'rate' => 600000,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Rate tidak boleh lebih besar dari nilai kontrak');

        $this->assertNull($kontrak->fresh()->rate);
    }

    public function test_update_nilai_kontrak_di_bawah_rate_lama_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrak = $this->makeKontrak($this->makeVendor(), 'draft', [
            'nilai_kontrak' => 500000,
            'rate'          => 400000,
        ]);

        $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'nilai_kontrak' => 300000,
        ])->assertStatus(422);

        $this->assertSame(500000.0, (float) $kontrak->fresh()->nilai_kontrak);
    }

    public function test_jumlah_trip_dan_jumlah_hari_tersimpan_dan_muncul_di_resource(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'   => $vendor->id_vendor,
            'mekanisme'   => 'unit_only',
            'jumlah_trip' => 120,
            'jumlah_hari' => 30,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jumlah_trip', 120)
            ->assertJsonPath('data.jumlah_hari', 30);

        $id = $res->json('data.id_kontrak_vendor');
        $this->assertDatabaseHas('kontrak_vendor', [
            'id_kontrak_vendor' => $id,
            'jumlah_trip'       => 120,
            'jumlah_hari'       => 30,
        ]);

        $this->putJson("/api/kontrak-vendor/{$id}", [
            'jumlah_trip' => null,
            'jumlah_hari' => 45,
        ])->assertStatus(200)
            ->assertJsonPath('data.jumlah_trip', null)
            ->assertJsonPath('data.jumlah_hari', 45);

        $this->getJson("/api/kontrak-vendor/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.jumlah_trip', null)
            ->assertJsonPath('data.jumlah_hari', 45);
    }

    public function test_jumlah_trip_negatif_ditolak_validasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'   => $vendor->id_vendor,
            'mekanisme'   => 'unit_only',
            'jumlah_trip' => -1,
        ])->assertStatus(422);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'   => $vendor->id_vendor,
            'mekanisme'   => 'unit_only',
            'jumlah_hari' => 4000,
        ])->assertStatus(422);
    }
}
