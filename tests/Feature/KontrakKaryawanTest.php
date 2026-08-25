<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakKaryawanTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(?string $idPerusahaan = null, string $nik = 'NIK-K-001'): object
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nik'                => $nik,
            'nama_karyawan'      => 'Karyawan Kontrak',
            'status_kepegawaian' => 'kontrak',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('karyawan')->where('id_karyawan', $id)->first();
    }

    private function makeKontrak(string $idKaryawan, string $mulai = '2026-01-01', ?string $selesai = '2026-12-31'): object
    {
        $id = (string) Str::uuid();
        DB::table('kontrak_karyawan')->insert([
            'id_kontrak'      => $id,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_karyawan'     => $idKaryawan,
            'jenis_kontrak'   => 'pkwt',
            'tanggal_mulai'   => $mulai,
            'tanggal_selesai' => $selesai,
            'dibuat_pada'     => now(),
        ]);
        return DB::table('kontrak_karyawan')->where('id_kontrak', $id)->first();
    }

    public function test_membuat_kontrak_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();

        $res = $this->postJson("/api/karyawan/{$karyawan->id_karyawan}/kontrak", [
            'jenis_kontrak'   => 'pkwt',
            'nomor_kontrak'   => 'PKWT/2026/001',
            'tanggal_mulai'   => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'keterangan'      => 'Kontrak tahun pertama',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_kontrak', 'pkwt')
            ->assertJsonPath('data.nomor_kontrak', 'PKWT/2026/001')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('kontrak_karyawan', [
            'id_karyawan'   => $karyawan->id_karyawan,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_kontrak' => 'PKWT/2026/001',
        ]);
    }

    public function test_list_kontrak_urut_terbaru_dan_flag_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $this->makeKontrak($karyawan->id_karyawan, '2024-01-01', '2024-12-31');
        $this->makeKontrak($karyawan->id_karyawan, '2025-01-01', null);

        $res = $this->getJson("/api/karyawan/{$karyawan->id_karyawan}/kontrak");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('2025-01-01', $data[0]['tanggal_mulai']);
        $this->assertTrue($data[0]['aktif']);
        $this->assertFalse($data[1]['aktif']);
    }

    public function test_menolak_tanggal_selesai_sebelum_mulai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();

        $res = $this->postJson("/api/karyawan/{$karyawan->id_karyawan}/kontrak", [
            'jenis_kontrak'   => 'pkwt',
            'tanggal_mulai'   => '2026-06-01',
            'tanggal_selesai' => '2026-01-01',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['tanggal_selesai']);
    }

    public function test_update_dan_delete_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $kontrak  = $this->makeKontrak($karyawan->id_karyawan);

        $resUpdate = $this->putJson("/api/karyawan/{$karyawan->id_karyawan}/kontrak/{$kontrak->id_kontrak}", [
            'jenis_kontrak' => 'pkwtt',
            'tanggal_selesai' => null,
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.jenis_kontrak', 'pkwtt')
            ->assertJsonPath('data.tanggal_selesai', null);

        $resDelete = $this->deleteJson("/api/karyawan/{$karyawan->id_karyawan}/kontrak/{$kontrak->id_kontrak}");
        $resDelete->assertStatus(200);

        $this->assertSoftDeleted('kontrak_karyawan', ['id_kontrak' => $kontrak->id_kontrak]);
    }

    public function test_membuat_kontrak_dengan_upload_file(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();

        $res = $this->post("/api/karyawan/{$karyawan->id_karyawan}/kontrak", [
            'jenis_kontrak'   => 'pkwt',
            'tanggal_mulai'   => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'file'            => UploadedFile::fake()->create('kontrak.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(201);
        $urlFile = $res->json('data.url_file');
        $this->assertNotNull($urlFile);

        $this->assertCount(1, Storage::disk('public')->allFiles('dokumen'));

        $tersimpan = (string) DB::table('kontrak_karyawan')->orderByDesc('dibuat_pada')->value('url_file');
        $this->assertStringStartsNotWith('http', $tersimpan);
        $this->assertStringStartsWith('dokumen/', $tersimpan);
    }

    public function test_update_kontrak_dengan_upload_file(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $karyawan = $this->makeKaryawan();
        $kontrak  = $this->makeKontrak($karyawan->id_karyawan);

        $res = $this->post("/api/karyawan/{$karyawan->id_karyawan}/kontrak/{$kontrak->id_kontrak}", [
            '_method' => 'PUT',
            'file'    => UploadedFile::fake()->create('kontrak-baru.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(200);
        $this->assertNotNull($res->json('data.url_file'));
        $this->assertCount(1, Storage::disk('public')->allFiles('dokumen'));
    }

    public function test_kontrak_perusahaan_lain_tidak_bisa_diakses(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $karyawanLain = $this->makeKaryawan($idPerusahaanLain, 'NIK-LAIN-01');

        $resList = $this->getJson("/api/karyawan/{$karyawanLain->id_karyawan}/kontrak");
        $resList->assertStatus(404);

        $resStore = $this->postJson("/api/karyawan/{$karyawanLain->id_karyawan}/kontrak", [
            'jenis_kontrak' => 'pkwt',
            'tanggal_mulai' => '2026-01-01',
        ]);
        $resStore->assertStatus(404);
    }
}
