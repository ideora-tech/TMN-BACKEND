<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanNikKtpUnikTest extends TestCase
{
    use RefreshDatabase;

    private const KTP = '3601101408890001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('SUPERADMIN');
    }

    private function karyawan(string $nik, string $nama, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert(array_merge([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nik' => $nik,
            'nama_karyawan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    public function test_nik_16_digit_baru_ditolak_bila_sudah_jadi_nik_ktp_karyawan_lain(): void
    {
        $this->karyawan('DRV-0006', 'Dede Supendi', ['nik_ktp' => self::KTP]);

        $res = $this->postJson('/api/karyawan', ['nik' => self::KTP, 'nama_karyawan' => 'Dede Supendi']);

        $res->assertStatus(422);
        $this->assertStringContainsString('sudah dipakai', (string) $res->json('message'));
        $this->assertStringContainsString('DRV-0006', (string) $res->json('message'));
    }

    public function test_nik_ktp_baru_ditolak_bila_sudah_dipakai_karyawan_lain(): void
    {
        $this->karyawan('K-001', 'Orang Pertama', ['nik_ktp' => self::KTP]);

        $this->postJson('/api/karyawan', ['nik' => 'K-002', 'nik_ktp' => self::KTP, 'nama_karyawan' => 'Orang Kedua'])
            ->assertStatus(422);
    }

    public function test_nik_ktp_baru_ditolak_bila_sama_dengan_nik_16_digit_karyawan_lain(): void
    {
        $this->karyawan(self::KTP, 'Orang Pertama');

        $this->postJson('/api/karyawan', ['nik' => 'DRV-0001', 'nik_ktp' => self::KTP, 'nama_karyawan' => 'Orang Kedua'])
            ->assertStatus(422);
    }

    public function test_nik_ktp_milik_karyawan_yang_sudah_dihapus_boleh_dipakai_lagi(): void
    {
        $this->karyawan('K-LAMA', 'Orang Lama', ['nik_ktp' => self::KTP, 'dihapus_pada' => now()]);

        $this->postJson('/api/karyawan', ['nik' => 'K-BARU', 'nik_ktp' => self::KTP, 'nama_karyawan' => 'Orang Baru'])
            ->assertStatus(201);
    }

    public function test_nik_ktp_perusahaan_lain_tidak_bentrok(): void
    {
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $this->karyawan('K-LAIN', 'Orang Lain', ['id_perusahaan' => $lain, 'nik_ktp' => self::KTP]);

        $this->postJson('/api/karyawan', ['nik' => 'K-SAYA', 'nik_ktp' => self::KTP, 'nama_karyawan' => 'Orang Saya'])
            ->assertStatus(201);
    }

    public function test_ubah_nik_ktp_ke_milik_karyawan_lain_ditolak(): void
    {
        $this->karyawan('K-001', 'Orang Pertama', ['nik_ktp' => self::KTP]);
        $kedua = $this->karyawan('K-002', 'Orang Kedua');

        $this->putJson("/api/karyawan/{$kedua}", ['nik_ktp' => self::KTP])->assertStatus(422);
    }

    public function test_edit_data_dobel_lama_yang_tidak_menyentuh_nomor_ktp_tetap_bisa(): void
    {
        $this->karyawan('DRV-0006', 'Dede Supendi', ['nik_ktp' => self::KTP]);
        $kembar = $this->karyawan(self::KTP, 'Dede Supendi', ['nik_ktp' => self::KTP]);

        $this->putJson("/api/karyawan/{$kembar}", ['nik' => self::KTP, 'nik_ktp' => self::KTP, 'telepon' => '0812'])
            ->assertStatus(200);
    }

    public function test_no_ktp_dari_halaman_supir_juga_dijaga(): void
    {
        $this->karyawan('K-001', 'Orang Pertama', ['nik_ktp' => self::KTP]);
        $idKaryawan = $this->karyawan('K-002', 'Orang Kedua');
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'nama' => 'Orang Kedua', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->putJson("/api/supir/{$idSupir}", ['no_ktp' => self::KTP])->assertStatus(422);

        $this->assertNull(DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik_ktp'));
    }
}
