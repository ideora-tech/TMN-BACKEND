<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstimasiBokTest extends TestCase
{
    use RefreshDatabase;

    private function makeRute(?float $jarak = 100.0, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'           => $id,
            'id_perusahaan'     => $idPerusahaan,
            'kode_rute'         => 'RT-' . Str::random(6),
            'nama_rute'         => 'Jakarta - Bandung',
            'estimasi_jarak_km' => $jarak,
            'aktif'             => 1,
            'dibuat_pada'       => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => $idPerusahaan,
            'kode_jenis'         => 'CDD-' . Str::random(4),
            'nama_jenis'         => 'CDD',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makeJenisBbmDenganHarga(float $harga = 10000): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_bbm')->insert([
            'id_jenis_bbm'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_bbm'      => 'Solar',
            'dibuat_pada'   => now(),
        ]);
        DB::table('harga_bbm')->insert([
            'id_harga_bbm'    => (string) Str::uuid(),
            'id_jenis_bbm'    => $id,
            'harga_per_liter' => $harga,
            'berlaku_mulai'   => now()->subDays(5)->toDateString(),
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    private function makeParameterBok(string $idJenisKendaraan, string $idJenisBbm): void
    {
        DB::table('parameter_bok')->insert([
            'id_parameter_bok'       => (string) Str::uuid(),
            'id_perusahaan'          => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan'     => $idJenisKendaraan,
            'id_jenis_bbm'           => $idJenisBbm,
            'konsumsi_km_per_liter'  => 5,
            'biaya_ban_per_km'       => 500,
            'biaya_servis_per_km'    => 500,
            'biaya_tetap_bulanan'    => 10000000,
            'utilisasi_km_per_bulan' => 5000,
            'margin_persen'          => 10,
            'aktif'                  => 1,
            'dibuat_pada'            => now(),
        ]);
    }

    public function test_estimasi_bok_menghitung_benar(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute(100.0);
        $idJenis = $this->makeJenisKendaraan();
        $this->makeParameterBok($idJenis, $this->makeJenisBbmDenganHarga(10000));

        // bok/km = 10jt/5000 + 10000/5 + 500 + 500 = 2000+2000+500+500 = 5000
        // pokok  = 5000*100 + tol 100000 = 600000 ; saran = 660000 (margin 10%)
        $res = $this->getJson("/api/v1/tarif-rute/estimasi-bok?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}&estimasi_tol=100000");

        $res->assertStatus(200)
            ->assertJsonPath('data.bok_per_km', 5000)
            ->assertJsonPath('data.harga_pokok', 600000)
            ->assertJsonPath('data.saran_harga', 660000)
            ->assertJsonPath('data.margin_persen_default', 10)
            ->assertJsonPath('data.komponen.biaya_tetap_per_km', 2000)
            ->assertJsonPath('data.komponen.biaya_bbm_per_km', 2000)
            ->assertJsonPath('data.komponen.jarak_km', 100);
    }

    public function test_estimasi_tanpa_tol(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute(100.0);
        $idJenis = $this->makeJenisKendaraan();
        $this->makeParameterBok($idJenis, $this->makeJenisBbmDenganHarga(10000));

        $res = $this->getJson("/api/v1/tarif-rute/estimasi-bok?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}");

        $res->assertStatus(200)->assertJsonPath('data.harga_pokok', 500000);
    }

    public function test_estimasi_tanpa_parameter_mengembalikan_null(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/tarif-rute/estimasi-bok?id_rute=' . $this->makeRute()
            . '&id_jenis_kendaraan=' . $this->makeJenisKendaraan());

        $res->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_estimasi_rute_tanpa_jarak_mengembalikan_null(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute(null);
        $idJenis = $this->makeJenisKendaraan();
        $this->makeParameterBok($idJenis, $this->makeJenisBbmDenganHarga());

        $res = $this->getJson("/api/v1/tarif-rute/estimasi-bok?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}");

        $res->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_estimasi_tanpa_harga_bbm_mengembalikan_null(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        // jenis bbm TANPA baris harga_bbm
        $idBbm = (string) Str::uuid();
        DB::table('jenis_bbm')->insert([
            'id_jenis_bbm'  => $idBbm,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_bbm'      => 'Solar',
            'dibuat_pada'   => now(),
        ]);
        $this->makeParameterBok($idJenis, $idBbm);

        $res = $this->getJson("/api/v1/tarif-rute/estimasi-bok?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}");

        $res->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_estimasi_rute_perusahaan_lain_404(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);

        $res = $this->getJson('/api/v1/tarif-rute/estimasi-bok?id_rute=' . $this->makeRute(100.0, $lain)
            . '&id_jenis_kendaraan=' . $this->makeJenisKendaraan());

        $res->assertStatus(404);
    }
}
