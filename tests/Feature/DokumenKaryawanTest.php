<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DokumenKaryawanTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(?string $idPerusahaan = null, string $nik = 'NIK-DOK-001', string $nama = 'Karyawan Dokumen'): object
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nik'           => $nik,
            'nama_karyawan' => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('karyawan')->where('id_karyawan', $id)->first();
    }

    private function makeDokumen(string $idKaryawan, string $jenis = 'KTP', ?string $berlakuSampai = null): object
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => $id,
            'id_karyawan'         => $idKaryawan,
            'jenis_dokumen'       => $jenis,
            'berlaku_sampai'      => $berlakuSampai,
            'dibuat_pada'         => now(),
        ]);
        return DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $id)->first();
    }

    public function test_create_dokumen_dengan_upload_file(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();

        $res = $this->postJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen", [
            'jenis_dokumen'  => 'Kontrak Kerja',
            'nomor'          => 'DOC/2026/01',
            'berlaku_sampai' => '2027-06-30',
            'file'           => UploadedFile::fake()->create('kontrak.pdf', 100, 'application/pdf'),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_dokumen', 'Kontrak Kerja')
            ->assertJsonPath('data.nomor', 'DOC/2026/01');

        $this->assertNotNull($res->json('data.url_file'));
        $filesTersimpan = Storage::disk('public')->allFiles('dokumen');
        $this->assertCount(1, $filesTersimpan);

        $tersimpan = (string) DB::table('dokumen_karyawan')->orderByDesc('dibuat_pada')->value('url_file');
        $this->assertStringStartsNotWith('http', $tersimpan);
        $this->assertStringStartsWith('dokumen/', $tersimpan);
    }

    public function test_list_dokumen_per_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $this->makeDokumen($karyawan->id_karyawan, 'KTP');
        $this->makeDokumen($karyawan->id_karyawan, 'NPWP');

        $res = $this->getJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen");

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));
    }

    public function test_list_global_scoped_perusahaan_dan_filter(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $this->makeDokumen($karyawan->id_karyawan, 'KTP');
        $this->makeDokumen($karyawan->id_karyawan, 'NPWP');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $karyawanLain = $this->makeKaryawan($idPerusahaanLain, 'NIK-LAIN-02', 'Orang Lain');
        $this->makeDokumen($karyawanLain->id_karyawan, 'KTP');

        $resAll = $this->getJson('/api/v1/dokumen-karyawan');
        $resAll->assertStatus(200);
        $this->assertCount(2, $resAll->json('data'));

        $resFilter = $this->getJson('/api/v1/dokumen-karyawan?jenis_dokumen=NPWP');
        $resFilter->assertStatus(200);
        $this->assertCount(1, $resFilter->json('data'));
        $this->assertSame('NPWP', $resFilter->json('data.0.jenis_dokumen'));
    }

    public function test_update_dan_delete_dokumen(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $dokumen  = $this->makeDokumen($karyawan->id_karyawan, 'Sertifikat', '2026-12-31');

        $resUpdate = $this->putJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen/{$dokumen->id_dokumen_karyawan}", [
            'nomor' => 'SERT-999',
        ]);
        $resUpdate->assertStatus(200)->assertJsonPath('data.nomor', 'SERT-999');

        $resDelete = $this->deleteJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen/{$dokumen->id_dokumen_karyawan}");
        $resDelete->assertStatus(200);

        $this->assertSoftDeleted('dokumen_karyawan', ['id_dokumen_karyawan' => $dokumen->id_dokumen_karyawan]);
    }

    public function test_dokumen_karyawan_perusahaan_lain_tidak_bisa_diakses(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $karyawanLain = $this->makeKaryawan($idPerusahaanLain, 'NIK-LAIN-03');
        $dokumenLain  = $this->makeDokumen($karyawanLain->id_karyawan);

        $this->getJson("/api/v1/karyawan/{$karyawanLain->id_karyawan}/dokumen")->assertStatus(404);
        $this->putJson("/api/v1/karyawan/{$karyawanLain->id_karyawan}/dokumen/{$dokumenLain->id_dokumen_karyawan}", ['nomor' => 'X'])->assertStatus(404);
    }

    public function test_menolak_file_selain_pdf_dan_gambar(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();

        $res = $this->postJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen", [
            'jenis_dokumen' => 'KTP',
            'file'          => UploadedFile::fake()->create('virus.exe', 100, 'application/octet-stream'),
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['file']);
    }
}
