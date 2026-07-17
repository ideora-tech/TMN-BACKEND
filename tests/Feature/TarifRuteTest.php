<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TarifRuteTest extends TestCase
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

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $id,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_membuat_tarif_umum_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/tarif-rute', [
            'id_rute'            => $this->makeRute(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'harga'              => 2500000,
            'estimasi_tol'       => 350000,
            'tanggal_mulai'      => now()->toDateString(),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.harga', 2500000)
            ->assertJsonPath('data.id_klien', null)
            ->assertJsonPath('data.nama_jenis', 'CDD');

        $this->assertDatabaseHas('tarif_rute', [
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'harga'         => 2500000,
        ]);
    }

    public function test_menolak_tarif_tanpa_field_wajib(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/tarif-rute', []);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['id_rute', 'id_jenis_kendaraan', 'harga', 'tanggal_mulai']);
    }

    public function test_menolak_rute_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();

        $res = $this->postJson('/api/v1/tarif-rute', [
            'id_rute'            => $this->makeRute($lain),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan('CDD'),
            'harga'              => 1000000,
            'tanggal_mulai'      => now()->toDateString(),
        ]);

        $res->assertStatus(404);
    }

    public function test_menolak_periode_tumpang_tindih(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $this->makeTarif([
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'tanggal_mulai'      => now()->subDays(10)->toDateString(),
            'tanggal_berakhir'   => now()->addDays(10)->toDateString(),
        ]);

        $res = $this->postJson('/api/v1/tarif-rute', [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga'              => 1750000,
            'tanggal_mulai'      => now()->toDateString(),
        ]);

        $res->assertStatus(422);
    }

    public function test_tarif_berjalan_otomatis_ditutup_saat_tarif_baru_masuk(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idLama  = $this->makeTarif([
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'tanggal_mulai'      => now()->subDays(30)->toDateString(),
            'tanggal_berakhir'   => null,
        ]);

        $res = $this->postJson('/api/v1/tarif-rute', [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga'              => 1750000,
            'tanggal_mulai'      => now()->toDateString(),
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('tarif_rute', [
            'id_tarif_rute'    => $idLama,
            'tanggal_berakhir' => now()->subDay()->toDateString(),
        ]);
    }

    public function test_menolak_tarif_baru_dengan_tanggal_mulai_sama(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $this->makeTarif([
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'tanggal_mulai'      => now()->toDateString(),
            'tanggal_berakhir'   => null,
        ]);

        $res = $this->postJson('/api/v1/tarif-rute', [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga'              => 1750000,
            'tanggal_mulai'      => now()->toDateString(),
        ]);

        $res->assertStatus(422);
    }

    public function test_kombinasi_klien_berbeda_tidak_dianggap_overlap(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $this->makeTarif([
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'id_klien'           => null,
        ]);

        $res = $this->postJson('/api/v1/tarif-rute', [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'id_klien'           => $this->makeKlien(),
            'harga'              => 1400000,
            'tanggal_mulai'      => now()->toDateString(),
        ]);

        $res->assertStatus(201);
    }

    public function test_list_dengan_filter_berlaku(): void
    {
        $this->actingAsRole('ADMIN');
        // berlaku hari ini
        $this->makeTarif();
        // sudah kedaluwarsa
        $this->makeTarif([
            'tanggal_mulai'    => now()->subDays(60)->toDateString(),
            'tanggal_berakhir' => now()->subDays(31)->toDateString(),
        ]);
        // baru berlaku besok
        $this->makeTarif(['tanggal_mulai' => now()->addDay()->toDateString()]);

        $res = $this->getJson('/api/v1/tarif-rute?berlaku=1');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
    }

    public function test_list_memuat_nama_relasi(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeTarif();

        $res = $this->getJson('/api/v1/tarif-rute');

        $res->assertStatus(200);
        $row = $res->json('data')[0];
        $this->assertSame('Jakarta - Bandung', $row['nama_rute']);
        $this->assertSame('CDD', $row['nama_jenis']);
        $this->assertNull($row['nama_klien']);
    }

    public function test_update_tarif_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->makeTarif();

        $res = $this->putJson("/api/v1/tarif-rute/{$id}", ['harga' => 1600000]);

        $res->assertStatus(200)->assertJsonPath('data.harga', 1600000);
    }

    public function test_update_menolak_periode_overlap(): void
    {
        $this->actingAsRole('ADMIN');
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $this->makeTarif([
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'tanggal_mulai'      => now()->subDays(30)->toDateString(),
            'tanggal_berakhir'   => now()->subDays(10)->toDateString(),
        ]);
        $idB = $this->makeTarif([
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'tanggal_mulai'      => now()->subDays(9)->toDateString(),
            'tanggal_berakhir'   => null,
        ]);

        // geser mulai B ke dalam periode A -> overlap
        $res = $this->putJson("/api/v1/tarif-rute/{$idB}", [
            'tanggal_mulai' => now()->subDays(20)->toDateString(),
        ]);

        $res->assertStatus(422);
    }

    public function test_hapus_tarif_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->makeTarif();

        $this->deleteJson("/api/v1/tarif-rute/{$id}")->assertStatus(200);

        $row = DB::table('tarif_rute')->where('id_tarif_rute', $id)->first();
        $this->assertNotNull($row->dihapus_pada);
        $this->assertCount(0, $this->getJson('/api/v1/tarif-rute')->json('data'));
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();
        $idLain = $this->makeTarif([
            'id_perusahaan'      => $lain,
            'id_rute'            => $this->makeRute($lain),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan('CDE', $lain),
        ]);

        $this->assertCount(0, $this->getJson('/api/v1/tarif-rute')->json('data'));
        $this->getJson("/api/v1/tarif-rute/{$idLain}")->assertStatus(404);
        $this->putJson("/api/v1/tarif-rute/{$idLain}", ['harga' => 1])->assertStatus(404);
        $this->deleteJson("/api/v1/tarif-rute/{$idLain}")->assertStatus(404);
    }
}
