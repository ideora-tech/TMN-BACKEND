<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kolom detail master armada — lihat
 * docs/superpowers/specs/2026-07-17-master-armada-kolom-lengkap-design.md
 */
class ArmadaKolomLengkapTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_menyimpan_kolom_detail_baru(): void
    {
        ArmadaModel::create([
            'id_perusahaan'       => self::PERUSAHAAN_ID,
            'nopol'               => 'B 1234 KLM',
            'merk'                => 'Hino',
            'nomor_rangka'        => 'MHFXW42G5N0000001',
            'nomor_mesin'         => '1TR-1234567',
            'warna'               => 'Putih',
            'jenis_bahan_bakar'   => 'solar',
            'kapasitas_muatan_kg' => 8000,
            'tanggal_beli'        => '2023-05-15',
            'harga_beli'          => 350000000,
            'kondisi_beli'        => 'baru',
            'url_foto'            => 'http://localhost/storage/armada/foto.jpg',
            'keterangan'          => 'Unit operasional Jakarta',
        ]);

        $this->assertDatabaseHas('armada', [
            'nopol'               => 'B 1234 KLM',
            'nomor_rangka'        => 'MHFXW42G5N0000001',
            'nomor_mesin'         => '1TR-1234567',
            'warna'               => 'Putih',
            'jenis_bahan_bakar'   => 'solar',
            'kapasitas_muatan_kg' => 8000,
            'kondisi_beli'        => 'baru',
            'keterangan'          => 'Unit operasional Jakarta',
        ]);
    }

    public function test_store_dengan_semua_field_baru_berhasil_dan_muncul_di_response(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/armada', [
            'nopol'               => 'B 5678 DTL',
            'merk'                => 'Hino',
            'model'               => 'Dutro',
            'tahun'               => 2023,
            'nomor_rangka'        => 'MHFXW42G5N0000002',
            'nomor_mesin'         => '1TR-7654321',
            'warna'               => 'Kuning',
            'jenis_bahan_bakar'   => 'solar',
            'kapasitas_muatan_kg' => 5000,
            'tanggal_beli'        => '2024-01-10',
            'harga_beli'          => 425000000.50,
            'kondisi_beli'        => 'bekas',
            'keterangan'          => 'Eks proyek Surabaya',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nomor_rangka', 'MHFXW42G5N0000002')
            ->assertJsonPath('data.nomor_mesin', '1TR-7654321')
            ->assertJsonPath('data.warna', 'Kuning')
            ->assertJsonPath('data.jenis_bahan_bakar', 'solar')
            ->assertJsonPath('data.kapasitas_muatan_kg', 5000)
            ->assertJsonPath('data.tanggal_beli', '2024-01-10')
            ->assertJsonPath('data.harga_beli', 425000000.5)
            ->assertJsonPath('data.kondisi_beli', 'bekas')
            ->assertJsonPath('data.keterangan', 'Eks proyek Surabaya');
    }

    public function test_store_nomor_rangka_duplikat_ditolak_409(): void
    {
        $this->actingAsRole('ADMIN');

        ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 1111 RGK',
            'nomor_rangka'  => 'MHFXW42G5N0000003',
        ]);

        $res = $this->postJson('/api/v1/armada', [
            'nopol'        => 'B 2222 RGK',
            'nomor_rangka' => 'MHFXW42G5N0000003',
        ]);

        $res->assertStatus(409);
    }

    public function test_update_nomor_rangka_sendiri_tidak_dianggap_duplikat(): void
    {
        $this->actingAsRole('ADMIN');

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 3333 RGK',
            'nomor_rangka'  => 'MHFXW42G5N0000004',
        ]);

        $res = $this->putJson("/api/v1/armada/{$armada->id_armada}", [
            'nomor_rangka' => 'MHFXW42G5N0000004',
            'warna'        => 'Merah',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.warna', 'Merah');
    }

    public function test_update_tanpa_field_baru_tidak_mengubah_nilai_lama(): void
    {
        $this->actingAsRole('ADMIN');

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 4444 TTP',
            'warna'         => 'Hijau',
            'harga_beli'    => 100000000,
        ]);

        $res = $this->putJson("/api/v1/armada/{$armada->id_armada}", [
            'merk' => 'Isuzu',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.warna', 'Hijau');
        $this->assertEquals(100000000, $res->json('data.harga_beli'));
    }

    public function test_jenis_bahan_bakar_tidak_valid_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/armada', [
            'nopol'             => 'B 5555 BBM',
            'jenis_bahan_bakar' => 'nuklir',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['jenis_bahan_bakar']);
    }

    public function test_kondisi_beli_tidak_valid_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/armada', [
            'nopol'        => 'B 6666 KND',
            'kondisi_beli' => 'rongsok',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['kondisi_beli']);
    }

    public function test_store_dengan_foto_mengisi_url_foto(): void
    {
        $this->actingAsRole('ADMIN');
        Storage::fake('public');

        $res = $this->post('/api/v1/armada', [
            'nopol' => 'B 7777 FTO',
            'merk'  => 'Hino',
            'foto'  => UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(201);
        $urlFoto = $res->json('data.url_foto');
        $this->assertNotNull($urlFoto);
        $this->assertStringContainsString('/storage/', $urlFoto);
    }

    public function test_update_dengan_foto_multipart_method_put_mengganti_url_foto(): void
    {
        $this->actingAsRole('ADMIN');
        Storage::fake('public');

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 8888 FTO',
        ]);

        $res = $this->post("/api/v1/armada/{$armada->id_armada}", [
            '_method' => 'PUT',
            'warna'   => 'Biru',
            'foto'    => UploadedFile::fake()->create('foto-baru.png', 100, 'image/png'),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(200)->assertJsonPath('data.warna', 'Biru');
        $this->assertNotNull($res->json('data.url_foto'));
    }

    public function test_store_foto_bukan_gambar_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->post('/api/v1/armada', [
            'nopol' => 'B 9990 FTO',
            'foto'  => UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(422);
    }

    public function test_store_id_jenis_kendaraan_tidak_terdaftar_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/armada', [
            'nopol'              => 'B 1212 JNS',
            'id_jenis_kendaraan' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['id_jenis_kendaraan']);
    }

    public function test_store_id_jenis_kendaraan_valid_diterima(): void
    {
        $this->actingAsRole('ADMIN');

        $idJenis = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenis,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'JNS-TST',
            'nama_jenis'         => 'Truk Tes',
            'dibuat_pada'        => now(),
        ]);

        $res = $this->postJson('/api/v1/armada', [
            'nopol'              => 'B 1313 JNS',
            'id_jenis_kendaraan' => $idJenis,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.id_jenis_kendaraan', $idJenis);
    }
}
