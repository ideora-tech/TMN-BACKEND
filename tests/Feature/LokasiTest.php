<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Lokasi\LokasiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LokasiTest extends TestCase
{
    use RefreshDatabase;

    private function makeLokasi(string $idPerusahaan, string $nama = 'Gudang Utama'): LokasiModel
    {
        return LokasiModel::create([
            'id_perusahaan' => $idPerusahaan,
            'nama_lokasi'   => $nama,
            'kota'          => 'Jakarta',
        ]);
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

    public function test_membuat_lokasi_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/lokasi', [
            'nama_lokasi' => 'Pelabuhan Tanjung Priok',
            'alamat'      => 'Jl. Pelabuhan No. 1',
            'kota'        => 'Jakarta Utara',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_lokasi', 'Pelabuhan Tanjung Priok')
            ->assertJsonPath('data.kota', 'Jakarta Utara')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('lokasi', [
            'nama_lokasi'   => 'Pelabuhan Tanjung Priok',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_menolak_membuat_lokasi_tanpa_nama(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/lokasi', [
            'kota' => 'Jakarta',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['nama_lokasi']);
    }

    public function test_list_lokasi_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');

        $this->makeLokasi(self::PERUSAHAAN_ID, 'Lokasi Sendiri');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $this->makeLokasi($idPerusahaanLain, 'Lokasi Perusahaan Lain');

        $res = $this->getJson('/api/v1/lokasi');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Lokasi Sendiri', $data[0]['nama_lokasi']);
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_show_lokasi_milik_sendiri_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $lokasi = $this->makeLokasi(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/lokasi/{$lokasi->id_lokasi}");

        $res->assertStatus(200)->assertJsonPath('data.id_lokasi', $lokasi->id_lokasi);
    }

    public function test_show_lokasi_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $lokasiLain = $this->makeLokasi($idPerusahaanLain, 'Lokasi Perusahaan Lain');

        $res = $this->getJson("/api/v1/lokasi/{$lokasiLain->id_lokasi}");

        $res->assertStatus(404);
    }

    public function test_update_lokasi_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $lokasi = $this->makeLokasi(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/lokasi/{$lokasi->id_lokasi}", [
            'nama_lokasi' => 'Gudang Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_lokasi', 'Gudang Diperbarui');
        $this->assertDatabaseHas('lokasi', [
            'id_lokasi'   => $lokasi->id_lokasi,
            'nama_lokasi' => 'Gudang Diperbarui',
        ]);
    }

    public function test_update_lokasi_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $lokasiLain = $this->makeLokasi($idPerusahaanLain, 'Lokasi Perusahaan Lain');

        $res = $this->putJson("/api/v1/lokasi/{$lokasiLain->id_lokasi}", [
            'nama_lokasi' => 'Coba Ubah',
        ]);

        $res->assertStatus(404);
    }

    public function test_hapus_lokasi_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $lokasi = $this->makeLokasi(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/lokasi/{$lokasi->id_lokasi}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('lokasi')->where('id_lokasi', $lokasi->id_lokasi)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson('/api/v1/lokasi')->json('data'));
    }

    public function test_hapus_lokasi_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->deleteJson('/api/v1/lokasi/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }
}
