<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanExitTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => 'Karyawan Exit Test',
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    public function test_membuat_karyawan_exit_berhasil_dan_menonaktifkan_karyawan(): void
    {
        $this->actingAsRole('ADMIN');
        $idKaryawan = $this->makeKaryawan();

        $res = $this->postJson('/api/v1/karyawan-exit', [
            'id_karyawan'     => $idKaryawan,
            'jenis_exit'      => 'resign',
            'tanggal_efektif' => '2026-08-01',
            'alasan'          => 'Pindah kerja',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.jenis_exit', 'resign');

        $this->assertDatabaseHas('karyawan_exit', [
            'id_karyawan' => $idKaryawan,
            'jenis_exit'  => 'resign',
        ]);

        $karyawan = DB::table('karyawan')->where('id_karyawan', $idKaryawan)->first();
        $this->assertSame(0, (int) $karyawan->aktif);
    }

    public function test_menolak_karyawan_exit_tanpa_jenis_exit(): void
    {
        $this->actingAsRole('ADMIN');
        $idKaryawan = $this->makeKaryawan();

        $res = $this->postJson('/api/v1/karyawan-exit', [
            'id_karyawan'     => $idKaryawan,
            'tanggal_efektif' => '2026-08-01',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['jenis_exit']);
    }

    public function test_karyawan_exit_muncul_di_exit_history_karyawan(): void
    {
        $this->actingAsRole('ADMIN');
        $idKaryawan = $this->makeKaryawan();

        $this->postJson('/api/v1/karyawan-exit', [
            'id_karyawan'     => $idKaryawan,
            'jenis_exit'      => 'pensiun',
            'tanggal_efektif' => '2026-09-01',
        ]);

        $res = $this->getJson("/api/v1/karyawan/{$idKaryawan}/exit-history");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('pensiun', $data[0]['jenis_exit']);
    }
}
