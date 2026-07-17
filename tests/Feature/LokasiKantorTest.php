<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LokasiKantorTest extends TestCase
{
    use RefreshDatabase;

    private function makeLokasiKantor(string $idPerusahaan, string $nama = 'Kantor Pusat'): object
    {
        $id = (string) Str::uuid();
        DB::table('lokasi_kantor')->insert([
            'id_lokasi'     => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_lokasi'   => 'LOK-' . Str::random(4),
            'nama_lokasi'   => $nama,
            'radius'        => 100,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('lokasi_kantor')->where('id_lokasi', $id)->first();
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

    public function test_membuat_lokasi_kantor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/lokasi-kantor', [
            'kode_lokasi' => 'LOK-01',
            'nama_lokasi' => 'Gudang Bekasi',
            'kota'        => 'Bekasi',
            'radius'      => 150,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_lokasi', 'Gudang Bekasi')
            ->assertJsonPath('data.radius', 150)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('lokasi_kantor', [
            'kode_lokasi'   => 'LOK-01',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_lokasi_kantor_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');

        $this->makeLokasiKantor(self::PERUSAHAAN_ID, 'Milik Sendiri');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $this->makeLokasiKantor($idPerusahaanLain, 'Milik Lain');

        $res = $this->getJson('/api/v1/lokasi-kantor');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_lokasi']);
    }

    public function test_show_lokasi_kantor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeLokasiKantor(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/lokasi-kantor/{$item->id_lokasi}");

        $res->assertStatus(200)->assertJsonPath('data.id_lokasi', $item->id_lokasi);
    }

    public function test_show_lokasi_kantor_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/lokasi-kantor/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }

    public function test_update_lokasi_kantor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeLokasiKantor(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/lokasi-kantor/{$item->id_lokasi}", [
            'nama_lokasi' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_lokasi', 'Nama Diperbarui');
        $this->assertDatabaseHas('lokasi_kantor', [
            'id_lokasi'   => $item->id_lokasi,
            'nama_lokasi' => 'Nama Diperbarui',
        ]);
    }

    public function test_hapus_lokasi_kantor_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeLokasiKantor(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/lokasi-kantor/{$item->id_lokasi}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('lokasi_kantor')->where('id_lokasi', $item->id_lokasi)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson('/api/v1/lokasi-kantor')->json('data'));
    }
}
