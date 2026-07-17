<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TarifRuteResolusiTest extends TestCase
{
    use RefreshDatabase;

    private function makeRute(string $idPerusahaan = self::PERUSAHAAN_ID, ?float $jarak = 150.0): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'           => $id,
            'id_perusahaan'     => $idPerusahaan,
            'kode_rute'         => 'RT-' . Str::random(6),
            'nama_rute'         => 'Jakarta - Bandung',
            'asal'              => 'Jakarta',
            'tujuan'            => 'Bandung',
            'estimasi_jarak_km' => $jarak,
            'aktif'             => 1,
            'dibuat_pada'       => now(),
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

    private function makeKlien(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_klien'    => 'KL-' . Str::random(6),
            'nama_klien'    => 'PT Klien Test',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeTarif(array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('tarif_rute')->insert(array_merge([
            'id_tarif_rute'      => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'id_klien'           => null,
            'harga'              => 1500000,
            'tanggal_mulai'      => now()->subDays(30)->toDateString(),
            'tanggal_berakhir'   => null,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ], $override));
        return $id;
    }

    public function test_resolusi_tarif_kontrak_klien_menang_atas_umum(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idKlien = $this->makeKlien();
        $this->makeTarif(['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'id_klien' => null, 'harga' => 1500000]);
        $this->makeTarif(['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'id_klien' => $idKlien, 'harga' => 1350000]);

        $res = $this->getJson("/api/v1/tarif-rute/resolusi?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}&id_klien={$idKlien}");

        $res->assertStatus(200)
            ->assertJsonPath('data.harga', 1350000)
            ->assertJsonPath('data.id_klien', $idKlien);
    }

    public function test_resolusi_fallback_ke_harga_umum(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idKlien = $this->makeKlien();
        $this->makeTarif(['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'id_klien' => null, 'harga' => 1500000]);

        $res = $this->getJson("/api/v1/tarif-rute/resolusi?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}&id_klien={$idKlien}");

        $res->assertStatus(200)
            ->assertJsonPath('data.harga', 1500000)
            ->assertJsonPath('data.id_klien', null);
    }

    public function test_resolusi_tanpa_tarif_mengembalikan_null(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/tarif-rute/resolusi?id_rute=' . $this->makeRute()
            . '&id_jenis_kendaraan=' . $this->makeJenisKendaraan());

        $res->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_resolusi_menghormati_tanggal(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        // tarif lama: berlaku 60..31 hari lalu
        $this->makeTarif([
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga'              => 1200000,
            'tanggal_mulai'      => now()->subDays(60)->toDateString(),
            'tanggal_berakhir'   => now()->subDays(31)->toDateString(),
        ]);

        // hari ini: tidak ada tarif berlaku
        $this->getJson("/api/v1/tarif-rute/resolusi?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}")
            ->assertStatus(200)->assertJsonPath('data', null);

        // 40 hari lalu: tarif lama yang kena
        $tanggal = now()->subDays(40)->toDateString();
        $this->getJson("/api/v1/tarif-rute/resolusi?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}&tanggal={$tanggal}")
            ->assertStatus(200)->assertJsonPath('data.harga', 1200000);
    }

    public function test_resolusi_tidak_bocor_antar_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        $idRute  = $this->makeRute($idPerusahaanLain);
        $idJenis = $this->makeJenisKendaraan('CDD', $idPerusahaanLain);
        $this->makeTarif([
            'id_perusahaan'      => $idPerusahaanLain,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
        ]);

        $res = $this->getJson("/api/v1/tarif-rute/resolusi?id_rute={$idRute}&id_jenis_kendaraan={$idJenis}");

        $res->assertStatus(200)->assertJsonPath('data', null);
    }
}
