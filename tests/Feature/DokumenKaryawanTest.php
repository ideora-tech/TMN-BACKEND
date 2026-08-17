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

    private function makeSupir(string $idKaryawan, string $tglKadaluarsa = '2026-07-31'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'           => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_karyawan'        => $idKaryawan,
            'nama'               => 'Supir Sinkron',
            'no_sim'             => 'SIM-001',
            'jenis_sim'          => 'B2',
            'tgl_kadaluarsa_sim' => $tglKadaluarsa,
            'status'             => 'aktif',
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function tglSimSupir(string $idSupir): ?string
    {
        $nilai = DB::table('supir')->where('id_supir', $idSupir)->value('tgl_kadaluarsa_sim');
        return $nilai !== null ? substr((string) $nilai, 0, 10) : null;
    }

    public function test_upload_dokumen_sim_sinkron_tgl_kadaluarsa_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $idSupir  = $this->makeSupir($karyawan->id_karyawan);

        $this->postJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen", [
            'jenis_dokumen'  => 'SIM',
            'nomor'          => '12345678',
            'berlaku_sampai' => '2030-08-07',
        ])->assertStatus(201);

        $this->assertSame('2030-08-07', $this->tglSimSupir($idSupir));
    }

    public function test_dokumen_sim_lama_tidak_menurunkan_tgl_kadaluarsa_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $idSupir  = $this->makeSupir($karyawan->id_karyawan);
        $this->makeDokumen($karyawan->id_karyawan, 'SIM', '2030-08-07');

        $this->postJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen", [
            'jenis_dokumen'  => 'SIM',
            'berlaku_sampai' => '2027-01-01',
        ])->assertStatus(201);

        $this->assertSame('2030-08-07', $this->tglSimSupir($idSupir));
    }

    public function test_hapus_dokumen_sim_terbaru_fallback_ke_sim_sebelumnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $idSupir  = $this->makeSupir($karyawan->id_karyawan);
        $this->makeDokumen($karyawan->id_karyawan, 'SIM', '2028-05-01');
        $baru = $this->makeDokumen($karyawan->id_karyawan, 'SIM', '2030-08-07');

        $this->deleteJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen/{$baru->id_dokumen_karyawan}")
            ->assertStatus(200);

        $this->assertSame('2028-05-01', $this->tglSimSupir($idSupir));
    }

    public function test_dokumen_non_sim_tidak_mengubah_tgl_kadaluarsa_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $idSupir  = $this->makeSupir($karyawan->id_karyawan);

        $this->postJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen", [
            'jenis_dokumen'  => 'KTP',
            'berlaku_sampai' => '2031-01-01',
        ])->assertStatus(201);

        $this->assertSame('2026-07-31', $this->tglSimSupir($idSupir));
    }

    public function test_migrasi_data_fix_sinkron_sim_dari_dokumen_lama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $idSupir  = $this->makeSupir($karyawan->id_karyawan, '2026-07-31');
        $this->makeDokumen($karyawan->id_karyawan, 'SIM', '2030-08-07');

        $migration = require database_path('migrations/2026_08_17_100001_sinkron_tgl_kadaluarsa_sim_dari_dokumen.php');
        $migration->up();

        $this->assertSame('2030-08-07', $this->tglSimSupir($idSupir));
    }

    public function test_update_tanggal_dokumen_sim_ikut_sinkron_ke_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $idSupir  = $this->makeSupir($karyawan->id_karyawan);
        $dok = $this->makeDokumen($karyawan->id_karyawan, 'SIM', '2029-03-01');

        $this->putJson("/api/v1/karyawan/{$karyawan->id_karyawan}/dokumen/{$dok->id_dokumen_karyawan}", [
            'jenis_dokumen'  => 'SIM',
            'berlaku_sampai' => '2031-03-01',
        ])->assertStatus(200);

        $this->assertSame('2031-03-01', $this->tglSimSupir($idSupir));
    }
}
