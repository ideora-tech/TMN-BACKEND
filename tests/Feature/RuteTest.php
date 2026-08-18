<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RuteTest extends TestCase
{
    use RefreshDatabase;

    private function makeRute(string $idPerusahaan, string $kode = 'RUT-01', string $nama = 'Rute Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_rute'     => $kode,
            'nama_rute'     => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('rute')->where('id_rute', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_rute_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/v1/rute', [
            'nama_rute' => 'Jakarta - Bandung',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_rute', 'Jakarta - Bandung')
            ->assertJsonPath('data.kode_rute', 'RT-0001')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('rute', ['kode_rute' => 'RT-0001', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_kode_rute_dari_input_diabaikan_pakai_kode_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/v1/rute', [
            'kode_rute' => 'MANUAL-999',
            'nama_rute' => 'Jakarta - Bekasi',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.kode_rute', 'RT-0001');
        $this->assertDatabaseMissing('rute', ['kode_rute' => 'MANUAL-999']);
    }

    public function test_menolak_saat_kode_otomatis_bentrok_dengan_data_existing(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeRute(self::PERUSAHAAN_ID, 'RT-0001');

        $res = $this->postJson('/api/v1/rute', [
            'nama_rute' => 'Duplikat',
        ]);

        $res->assertStatus(409);
    }

    public function test_endpoint_modul_lama_yang_dihapus_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/v1/tarif-rute')->assertStatus(404);
    }

    public function test_list_rute_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeRute(self::PERUSAHAAN_ID, 'RUT-01', 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeRute($idLain, 'RUT-01', 'Milik Lain');

        $res = $this->getJson('/api/v1/rute');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_rute']);
    }

    public function test_search_rute_by_nama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeRute(self::PERUSAHAAN_ID, 'RUT-S1', 'Jakarta Surabaya');
        $this->makeRute(self::PERUSAHAAN_ID, 'RUT-S2', 'Bandung Semarang');

        $res = $this->getJson('/api/v1/rute?search=Jakarta');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Jakarta Surabaya', $data[0]['nama_rute']);
    }

    public function test_update_rute_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeRute(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/rute/{$item->id_rute}", [
            'nama_rute' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_rute', 'Nama Diperbarui');
    }

    public function test_hapus_rute_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeRute(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/rute/{$item->id_rute}");
        $res->assertStatus(200);

        $row = DB::table('rute')->where('id_rute', $item->id_rute)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
