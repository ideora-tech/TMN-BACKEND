<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntervalPerawatanResolusiTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisPerawatan(string $nama = 'Ganti Oli', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id,
            'id_perusahaan'      => $idPerusahaan,
            'nama'               => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $nama = 'CDD', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => $idPerusahaan,
            'kode_jenis'         => strtoupper($nama) . '-' . Str::random(4),
            'nama_jenis'         => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_resolusi_mengembalikan_interval_hari_saat_kombinasi_cocok(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();
        $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'interval_hari' => 180,
        ]);

        $res = $this->getJson("/api/v1/interval-perawatan/resolusi?id_jenis_perawatan={$idJenis}&id_jenis_kendaraan={$idKendaraan}");

        $res->assertStatus(200)->assertJsonPath('data.interval_hari', 180);
    }

    public function test_resolusi_tanpa_kombinasi_cocok_mengembalikan_null(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/interval-perawatan/resolusi?id_jenis_perawatan=' . $this->makeJenisPerawatan()
            . '&id_jenis_kendaraan=' . $this->makeJenisKendaraan());

        $res->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_resolusi_tidak_bocor_antar_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();
        $idJenis = $this->makeJenisPerawatan('Ganti Oli', $lain);
        $idKendaraan = $this->makeJenisKendaraan('CDD', $lain);
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => (string) Str::uuid(),
            'id_perusahaan'         => $lain,
            'id_jenis_perawatan'    => $idJenis,
            'id_jenis_kendaraan'    => $idKendaraan,
            'interval_hari'         => 180,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);

        $res = $this->getJson("/api/v1/interval-perawatan/resolusi?id_jenis_perawatan={$idJenis}&id_jenis_kendaraan={$idKendaraan}");

        $res->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_resolusi_menolak_tanpa_query_wajib(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/interval-perawatan/resolusi');

        $res->assertStatus(422);
    }
}
