<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirPindahKaryawanDokumenTest extends TestCase
{
    use RefreshDatabase;

    private function karyawan(string $nik): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nik' => $nik,
            'nama_karyawan' => 'Dede Supendi', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    public function test_dokumen_supir_yang_menempel_di_karyawan_lama_ikut_pindah_saat_tautan_diganti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lama = $this->karyawan('DRV-0006');
        $baru = $this->karyawan('3601101408890001');
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $lama,
            'nama' => 'Dede Supendi', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $dokSupir = (string) Str::uuid();
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => $dokSupir, 'id_karyawan' => $lama, 'id_supir' => $idSupir,
            'jenis_dokumen' => 'SIM', 'nomor' => '13218905000189', 'berlaku_sampai' => '2029-05-11', 'dibuat_pada' => now(),
        ]);
        $dokHr = (string) Str::uuid();
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => $dokHr, 'id_karyawan' => $lama, 'id_supir' => null,
            'jenis_dokumen' => 'IJAZAH', 'nomor' => 'IJ-1', 'dibuat_pada' => now(),
        ]);

        $this->putJson("/api/supir/{$idSupir}", ['id_karyawan' => $baru])->assertStatus(200);

        $this->assertSame($baru, DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $dokSupir)->value('id_karyawan'));
        $this->assertSame($lama, DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $dokHr)->value('id_karyawan'));

        $daftar = $this->getJson("/api/supir/{$idSupir}/dokumen-karyawan")->assertStatus(200)->json('data');
        $this->assertSame([$dokSupir], array_column($daftar, 'id_dokumen_karyawan'));
    }
}
