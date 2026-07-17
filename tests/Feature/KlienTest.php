<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KlienTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(string $idPerusahaan, string $kode = 'KLN-01', string $nama = 'Klien Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_klien'    => $kode,
            'nama_klien'    => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_klien_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/klien', [
            'kode_klien' => 'KLN-BARU',
            'nama_klien' => 'PT Contoh Jaya',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_klien', 'PT Contoh Jaya')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('klien', ['kode_klien' => 'KLN-BARU', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_menolak_kode_klien_duplikat(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKlien(self::PERUSAHAAN_ID, 'KLN-DUP');

        $res = $this->postJson('/api/v1/klien', [
            'kode_klien' => 'KLN-DUP',
            'nama_klien' => 'Duplikat',
        ]);

        $res->assertStatus(409);
    }

    public function test_list_klien_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKlien(self::PERUSAHAAN_ID, 'KLN-01', 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeKlien($idLain, 'KLN-02', 'Milik Lain');

        $res = $this->getJson('/api/v1/klien');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_klien']);
    }

    public function test_show_klien_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKlien(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/klien/{$item->id_klien}");

        $res->assertStatus(200)->assertJsonPath('data.id_klien', $item->id_klien);
    }

    public function test_update_klien_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKlien(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/klien/{$item->id_klien}", [
            'nama_klien' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_klien', 'Nama Diperbarui');
    }

    public function test_hapus_klien_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKlien(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/klien/{$item->id_klien}");
        $res->assertStatus(200);

        $row = DB::table('klien')->where('id_klien', $item->id_klien)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
