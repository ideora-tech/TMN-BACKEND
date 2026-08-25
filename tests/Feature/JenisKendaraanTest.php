<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JenisKendaraanTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisKendaraan(string $idPerusahaan, string $kode = 'TRK-01', string $nama = 'Truk Engkel'): object
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => $idPerusahaan,
            'kode_jenis'         => $kode,
            'nama_jenis'         => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('jenis_kendaraan')->where('id_jenis_kendaraan', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        return $idPerusahaanLain;
    }

    public function test_membuat_jenis_kendaraan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/jenis-kendaraan', [
            'kode_jenis'       => 'TRK-02',
            'nama_jenis'       => 'Truk Tronton',
            'kapasitas_muatan' => 8000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_jenis', 'Truk Tronton')
            ->assertJsonPath('data.kapasitas_muatan', 8000)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('jenis_kendaraan', [
            'kode_jenis'    => 'TRK-02',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_menolak_kode_jenis_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeJenisKendaraan(self::PERUSAHAAN_ID, 'TRK-01');

        $res = $this->postJson('/api/jenis-kendaraan', [
            'kode_jenis' => 'TRK-01',
            'nama_jenis' => 'Truk Duplikat',
        ]);

        $res->assertStatus(409);
    }

    public function test_list_jenis_kendaraan_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->makeJenisKendaraan(self::PERUSAHAAN_ID, 'TRK-01', 'Milik Sendiri');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $this->makeJenisKendaraan($idPerusahaanLain, 'TRK-01', 'Milik Lain');

        $res = $this->getJson('/api/jenis-kendaraan');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_jenis']);
    }

    public function test_show_jenis_kendaraan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeJenisKendaraan(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/jenis-kendaraan/{$item->id_jenis_kendaraan}");

        $res->assertStatus(200)->assertJsonPath('data.id_jenis_kendaraan', $item->id_jenis_kendaraan);
    }

    public function test_show_jenis_kendaraan_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->getJson('/api/jenis-kendaraan/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }

    public function test_update_jenis_kendaraan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeJenisKendaraan(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/jenis-kendaraan/{$item->id_jenis_kendaraan}", [
            'nama_jenis' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_jenis', 'Nama Diperbarui');
        $this->assertDatabaseHas('jenis_kendaraan', [
            'id_jenis_kendaraan' => $item->id_jenis_kendaraan,
            'nama_jenis'         => 'Nama Diperbarui',
        ]);
    }

    public function test_list_dengan_search_menyaring_berdasarkan_kode_atau_nama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeJenisKendaraan(self::PERUSAHAAN_ID, 'TRK-01', 'Truk Engkel');
        $this->makeJenisKendaraan(self::PERUSAHAAN_ID, 'PIK-01', 'Pick Up');

        $resByNama = $this->getJson('/api/jenis-kendaraan?search=Engkel');
        $resByNama->assertStatus(200);
        $dataByNama = $resByNama->json('data');
        $this->assertCount(1, $dataByNama);
        $this->assertSame('Truk Engkel', $dataByNama[0]['nama_jenis']);

        $resByKode = $this->getJson('/api/jenis-kendaraan?search=PIK-01');
        $resByKode->assertStatus(200);
        $dataByKode = $resByKode->json('data');
        $this->assertCount(1, $dataByKode);
        $this->assertSame('Pick Up', $dataByKode[0]['nama_jenis']);
    }

    public function test_list_dengan_filter_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $aktifItem = $this->makeJenisKendaraan(self::PERUSAHAAN_ID, 'TRK-01', 'Truk Aktif');
        $nonaktifId = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $nonaktifId,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'TRK-99',
            'nama_jenis'         => 'Truk Nonaktif',
            'aktif'              => 0,
            'dibuat_pada'        => now(),
        ]);

        $resAktif = $this->getJson('/api/jenis-kendaraan?aktif=1');
        $resAktif->assertStatus(200);
        $dataAktif = $resAktif->json('data');
        $this->assertCount(1, $dataAktif);
        $this->assertSame($aktifItem->id_jenis_kendaraan, $dataAktif[0]['id_jenis_kendaraan']);

        $resNonaktif = $this->getJson('/api/jenis-kendaraan?aktif=0');
        $resNonaktif->assertStatus(200);
        $dataNonaktif = $resNonaktif->json('data');
        $this->assertCount(1, $dataNonaktif);
        $this->assertSame($nonaktifId, $dataNonaktif[0]['id_jenis_kendaraan']);
    }

    public function test_list_dengan_kombinasi_search_dan_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeJenisKendaraan(self::PERUSAHAAN_ID, 'TRK-01', 'Truk Engkel');
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'TRK-02',
            'nama_jenis'         => 'Truk Engkel Nonaktif',
            'aktif'              => 0,
            'dibuat_pada'        => now(),
        ]);

        $res = $this->getJson('/api/jenis-kendaraan?search=Engkel&aktif=1');
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Truk Engkel', $data[0]['nama_jenis']);
    }

    public function test_hapus_jenis_kendaraan_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeJenisKendaraan(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/jenis-kendaraan/{$item->id_jenis_kendaraan}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('jenis_kendaraan')->where('id_jenis_kendaraan', $item->id_jenis_kendaraan)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson('/api/jenis-kendaraan')->json('data'));
    }
}
