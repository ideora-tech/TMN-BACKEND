<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupir(string $idPerusahaan, string $nama = 'Supir Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supir')->where('id_supir', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_supir_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/supir', [
            'nama'   => 'Budi Santoso',
            'no_sim' => 'SIM-12345',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama', 'Budi Santoso')
            ->assertJsonPath('data.status', 'aktif');

        $this->assertDatabaseHas('supir', ['nama' => 'Budi Santoso', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_list_supir_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeSupir(self::PERUSAHAAN_ID, 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeSupir($idLain, 'Milik Lain');

        $res = $this->getJson('/api/v1/supir');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama']);
    }

    public function test_show_supir_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/supir/{$item->id_supir}");

        $res->assertStatus(200)->assertJsonPath('data.id_supir', $item->id_supir);
    }

    public function test_update_supir_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/supir/{$item->id_supir}", [
            'nama' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama', 'Nama Diperbarui');
    }

    public function test_hapus_supir_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/supir/{$item->id_supir}");
        $res->assertStatus(200);

        $row = DB::table('supir')->where('id_supir', $item->id_supir)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
