<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntervalPerawatanTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisPerawatan(string $nama = 'Ganti Oli', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id,
            'id_perusahaan'      => $idPerusahaan,
            'nama'               => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
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

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_interval_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_hari'      => 180,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.interval_hari', 180)
            ->assertJsonPath('data.nama_jenis_perawatan', 'Ganti Oli')
            ->assertJsonPath('data.nama_jenis_kendaraan', 'CDD');

        $this->assertDatabaseHas('interval_perawatan', [
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'interval_hari' => 180,
        ]);
    }

    public function test_menolak_tanpa_field_wajib(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/interval-perawatan', []);

        $res->assertStatus(422)->assertJsonValidationErrors(['id_jenis_perawatan', 'id_jenis_kendaraan', 'interval_hari']);
    }

    public function test_menolak_duplikat_kombinasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();
        $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'interval_hari' => 180,
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'interval_hari' => 90,
        ]);

        $res->assertStatus(422);
    }

    public function test_kombinasi_jenis_kendaraan_berbeda_tidak_dianggap_duplikat(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $this->makeJenisKendaraan('CDD'), 'interval_hari' => 180,
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $this->makeJenisKendaraan('CDE'), 'interval_hari' => 150,
        ]);

        $res->assertStatus(201);
    }

    public function test_menolak_jenis_perawatan_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();

        $res = $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan('Ganti Oli', $lain),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_hari'      => 180,
        ]);

        $res->assertStatus(404);
    }

    public function test_list_memuat_nama_relasi(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_hari'      => 180,
        ]);

        $res = $this->getJson('/api/v1/interval-perawatan');

        $res->assertStatus(200);
        $row = $res->json('data')[0];
        $this->assertSame('Ganti Oli', $row['nama_jenis_perawatan']);
        $this->assertSame('CDD', $row['nama_jenis_kendaraan']);
    }

    public function test_update_interval_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_hari'      => 180,
        ])->json('data.id_interval_perawatan');

        $res = $this->putJson("/api/v1/interval-perawatan/{$id}", ['interval_hari' => 200]);

        $res->assertStatus(200)->assertJsonPath('data.interval_hari', 200);
    }

    public function test_update_menolak_duplikat_kombinasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenisA = $this->makeJenisPerawatan('Ganti Oli');
        $idJenisB = $this->makeJenisPerawatan('Servis Besar');
        $idKendaraan = $this->makeJenisKendaraan();
        $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $idJenisA, 'id_jenis_kendaraan' => $idKendaraan, 'interval_hari' => 180,
        ])->assertStatus(201);
        $idB = $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $idJenisB, 'id_jenis_kendaraan' => $idKendaraan, 'interval_hari' => 600,
        ])->json('data.id_interval_perawatan');

        $res = $this->putJson("/api/v1/interval-perawatan/{$idB}", ['id_jenis_perawatan' => $idJenisA]);

        $res->assertStatus(422);
    }

    public function test_hapus_interval_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->postJson('/api/v1/interval-perawatan', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_hari'      => 180,
        ])->json('data.id_interval_perawatan');

        $this->deleteJson("/api/v1/interval-perawatan/{$id}")->assertStatus(200);

        $row = DB::table('interval_perawatan')->where('id_interval_perawatan', $id)->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();
        $id = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $id,
            'id_perusahaan'         => $lain,
            'id_jenis_perawatan'    => $this->makeJenisPerawatan('Ganti Oli', $lain),
            'id_jenis_kendaraan'    => $this->makeJenisKendaraan('CDD', $lain),
            'interval_hari'         => 180,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);

        $this->assertCount(0, $this->getJson('/api/v1/interval-perawatan')->json('data'));
        $this->getJson("/api/v1/interval-perawatan/{$id}")->assertStatus(404);
        $this->putJson("/api/v1/interval-perawatan/{$id}", ['interval_hari' => 1])->assertStatus(404);
        $this->deleteJson("/api/v1/interval-perawatan/{$id}")->assertStatus(404);
    }
}
