<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakKaryawanNomorOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => 'Karyawan Nomor',
            'status_kepegawaian' => 'kontrak',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);

        return $id;
    }

    private function buatKontrak(string $idKaryawan, array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/karyawan/{$idKaryawan}/kontrak", array_merge([
            'jenis_kontrak' => 'pkwt',
            'tanggal_mulai' => '2026-01-01',
        ], $override));
    }

    public function test_nomor_kosong_dibuat_otomatis_dengan_jenis_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan();
        $bulanTahun = now()->format('m/Y');

        $pkwt   = $this->buatKontrak($idKaryawan)->assertStatus(201);
        $magang = $this->buatKontrak($idKaryawan, ['jenis_kontrak' => 'magang', 'nomor_kontrak' => ''])->assertStatus(201);

        $this->assertSame("001/PKWT/HRD/SLI/{$bulanTahun}", $pkwt->json('data.nomor_kontrak'));
        // Satu urutan untuk semua jenis kontrak
        $this->assertSame("002/MAGANG/HRD/SLI/{$bulanTahun}", $magang->json('data.nomor_kontrak'));
    }

    public function test_nomor_manual_tetap_dipakai(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->buatKontrak($this->makeKaryawan(), ['nomor_kontrak' => 'PKWT/2026/001'])
            ->assertStatus(201)
            ->assertJsonPath('data.nomor_kontrak', 'PKWT/2026/001');
    }

    public function test_prefix_kontrak_karyawan_bisa_diatur_dan_boleh_tanpa_jenis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->putJson('/api/pengaturan-kode/kontrak_karyawan', [
            'prefix' => 'PK/HR/SLI', 'panjang_digit' => 3, 'reset' => 'tahunan',
        ])->assertStatus(200);

        $res = $this->buatKontrak($this->makeKaryawan(), ['jenis_kontrak' => 'pkwtt'])->assertStatus(201);
        $this->assertSame('001/PK/HR/SLI/' . now()->format('m/Y'), $res->json('data.nomor_kontrak'));
    }

    public function test_placeholder_jenis_hanya_diterima_untuk_kontrak_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->putJson('/api/pengaturan-kode/kontrak_karyawan', [
            'prefix' => '{JENIS}/HRD', 'panjang_digit' => 3, 'reset' => 'tahunan',
        ])->assertStatus(200);
        $this->putJson('/api/pengaturan-kode/kontrak_vendor', [
            'prefix' => '{JENIS}/VM', 'panjang_digit' => 3, 'reset' => 'tahunan',
        ])->assertStatus(422);
        $this->putJson('/api/pengaturan-kode/kontrak_karyawan', [
            'prefix' => '{NAMA}/HRD', 'panjang_digit' => 3, 'reset' => 'tahunan',
        ])->assertStatus(422);
    }

    public function test_daftar_pengaturan_kode_memuat_kontrak_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $baris = collect($this->getJson('/api/pengaturan-kode')->assertStatus(200)->json('data'))
            ->firstWhere('entitas', 'kontrak_karyawan');

        $this->assertSame('{JENIS}/HRD/SLI', $baris['prefix']);
        $this->assertSame('surat', $baris['format']);
        $this->assertSame(['JENIS'], $baris['placeholder']);
    }
}
