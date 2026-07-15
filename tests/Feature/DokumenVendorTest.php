<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Notifikasi\NotifikasiModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DokumenVendorTest extends TestCase
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

    public function test_membuat_dokumen_vendor_dengan_upload_file(): void
    {
        Storage::fake('public');
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen", [
            'jenis_dokumen'  => 'SIUP',
            'nomor'          => 'SIUP-001',
            'berlaku_sampai' => now()->addDays(10)->toDateString(),
            'file'           => UploadedFile::fake()->create('siup.pdf', 100),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.jenis_dokumen', 'SIUP')
            ->assertJsonPath('data.id_vendor', $vendor->id_vendor);

        $this->assertIsString($res->json('data.url_file'));
        $this->assertNotEmpty($res->json('data.url_file'));

        $this->assertDatabaseHas('dokumen_vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'jenis_dokumen' => 'SIUP',
            'nomor'         => 'SIUP-001',
        ]);
    }

    public function test_list_dokumen_vendor_by_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        $this->postJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen", [
            'jenis_dokumen'  => 'NPWP',
            'berlaku_sampai' => now()->addDays(15)->toDateString(),
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen");

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('NPWP', $res->json('data.0.jenis_dokumen'));
        $this->assertArrayHasKey('meta', $res->json());
    }

    public function test_update_dokumen_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        $createRes = $this->postJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen", [
            'jenis_dokumen'  => 'NPWP',
            'berlaku_sampai' => now()->addDays(15)->toDateString(),
        ]);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id_dokumen_vendor');

        $res = $this->putJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen/{$id}", [
            'nomor' => 'NPWP-999',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nomor', 'NPWP-999');

        $this->assertDatabaseHas('dokumen_vendor', [
            'id_dokumen_vendor' => $id,
            'nomor'             => 'NPWP-999',
        ]);
    }

    public function test_menolak_update_dokumen_vendor_milik_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();

        $idDokumen = (string) Str::uuid();
        DB::table('dokumen_vendor')->insert([
            'id_dokumen_vendor' => $idDokumen,
            'id_vendor'         => $vendorLain->id_vendor,
            'jenis_dokumen'     => 'SIUP',
            'nomor'             => 'SIUP-LAMA',
            'berlaku_sampai'    => now()->addDays(10)->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        $res = $this->putJson("/api/v1/vendor/{$vendorLain->id_vendor}/dokumen/{$idDokumen}", [
            'nomor' => 'SIUP-BARU',
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseHas('dokumen_vendor', [
            'id_dokumen_vendor' => $idDokumen,
            'nomor'             => 'SIUP-LAMA',
        ]);
    }

    public function test_soft_delete_dokumen_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        $createRes = $this->postJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen", [
            'jenis_dokumen'  => 'NPWP',
            'berlaku_sampai' => now()->addDays(15)->toDateString(),
        ]);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id_dokumen_vendor');

        $res = $this->deleteJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen/{$id}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('dokumen_vendor')->where('id_dokumen_vendor', $id)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->getJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen")
            ->assertStatus(200);
        $this->assertCount(0, $this->getJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen")->json('data'));
    }

    public function test_expiring_hanya_mengembalikan_dokumen_dalam_30_hari_milik_perusahaan_user(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        // Dalam rentang 30 hari -> muncul
        $this->postJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen", [
            'jenis_dokumen'  => 'SIUP',
            'berlaku_sampai' => now()->addDays(20)->toDateString(),
        ])->assertStatus(201);

        // Lebih dari 30 hari -> tidak muncul
        $this->postJson("/api/v1/vendor/{$vendor->id_vendor}/dokumen", [
            'jenis_dokumen'  => 'NPWP',
            'berlaku_sampai' => now()->addDays(60)->toDateString(),
        ])->assertStatus(201);

        // Milik perusahaan lain -> tidak muncul walau dalam rentang 30 hari
        $vendorLain = $this->makeVendorPerusahaanLain();
        DB::table('dokumen_vendor')->insert([
            'id_dokumen_vendor' => (string) Str::uuid(),
            'id_vendor'         => $vendorLain->id_vendor,
            'jenis_dokumen'     => 'SIUP',
            'berlaku_sampai'    => now()->addDays(5)->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        $res = $this->getJson('/api/v1/dokumen-vendor/expiring');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('SIUP', $data[0]['jenis_dokumen']);
        $this->assertSame($vendor->id_vendor, $data[0]['id_vendor']);
    }

    public function test_command_notifikasi_dokumen_kadaluarsa_membuat_notifikasi_dokumen_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        DB::table('dokumen_vendor')->insert([
            'id_dokumen_vendor' => (string) Str::uuid(),
            'id_vendor'         => $vendor->id_vendor,
            'jenis_dokumen'     => 'SIUP',
            'berlaku_sampai'    => now()->addDays(5)->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        $this->artisan('notifikasi:dokumen-kadaluarsa');

        $notif = NotifikasiModel::where('referensi_tipe', 'dokumen_vendor')->first();

        $this->assertNotNull($notif);
        $this->assertSame(self::PERUSAHAAN_ID, $notif->id_perusahaan);
        $this->assertStringStartsWith('[SEGERA]', $notif->judul);
        $this->assertStringContainsString($vendor->nama_vendor, $notif->judul);
    }

    public function test_command_notifikasi_dokumen_kadaluarsa_idempoten(): void
    {
        $this->actingAsRole('ADMIN');
        $vendor = $this->makeVendor();

        DB::table('dokumen_vendor')->insert([
            'id_dokumen_vendor' => (string) Str::uuid(),
            'id_vendor'         => $vendor->id_vendor,
            'jenis_dokumen'     => 'SIUP',
            'berlaku_sampai'    => now()->addDays(5)->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        $this->artisan('notifikasi:dokumen-kadaluarsa');
        $this->artisan('notifikasi:dokumen-kadaluarsa');

        $this->assertSame(
            1,
            NotifikasiModel::where('referensi_tipe', 'dokumen_vendor')->count()
        );
    }
}
