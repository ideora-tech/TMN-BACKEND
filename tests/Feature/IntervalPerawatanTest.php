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
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_bulan'     => 6,
            'interval_km'        => 10000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.interval_bulan', 6)
            ->assertJsonPath('data.interval_km', 10000)
            ->assertJsonPath('data.nama_jenis_kendaraan', 'CDD')
            ->assertJsonPath('data.label', 'Tiap 10.000 km / 6 bulan')
            ->assertJsonPath('data.sparepart', []);

        $this->assertDatabaseHas('interval_perawatan', [
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'interval_bulan' => 6,
        ]);
    }

    public function test_menolak_tanpa_field_wajib(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/interval-perawatan', []);

        $res->assertStatus(422)->assertJsonValidationErrors(['id_jenis_kendaraan']);
    }

    public function test_menolak_keduanya_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
        ]);

        $res->assertStatus(422)->assertJsonPath('message', 'Isi minimal interval kilometer atau interval bulan');
    }

    public function test_membuat_interval_tanpa_bulan_hanya_km(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_km'        => 10000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.interval_bulan', null)
            ->assertJsonPath('data.interval_km', 10000)
            ->assertJsonPath('data.label', 'Tiap 10.000 km');
    }

    public function test_membuat_interval_tanpa_km_hanya_bulan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_bulan'     => 6,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.interval_km', null)
            ->assertJsonPath('data.interval_bulan', 6)
            ->assertJsonPath('data.label', 'Tiap 6 bulan');
    }

    public function test_menolak_duplikat_kombinasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKendaraan = $this->makeJenisKendaraan();
        $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_bulan' => 6, 'interval_km' => 10000,
        ])->assertStatus(201);

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_bulan' => 6, 'interval_km' => 10000,
        ]);

        $res->assertStatus(422)->assertJsonPath('message', 'Paket servis dengan interval ini sudah ada untuk jenis kendaraan tersebut');
    }

    public function test_paket_beda_interval_untuk_jenis_kendaraan_sama_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKendaraan = $this->makeJenisKendaraan();
        $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_bulan' => 6, 'interval_km' => 10000,
        ])->assertStatus(201);

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_bulan' => 24, 'interval_km' => 40000,
        ]);

        $res->assertStatus(201);
    }

    public function test_kombinasi_jenis_kendaraan_berbeda_tidak_dianggap_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan('CDD'), 'interval_bulan' => 6, 'interval_km' => 10000,
        ])->assertStatus(201);

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan('CDE'), 'interval_bulan' => 5, 'interval_km' => 8000,
        ]);

        $res->assertStatus(201);
    }

    public function test_menolak_jenis_kendaraan_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = $this->makePerusahaanLain();

        $res = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan('CDD', $lain),
            'interval_bulan'     => 6,
            'interval_km'        => 10000,
        ]);

        $res->assertStatus(404);
    }

    public function test_list_memuat_nama_relasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_bulan'     => 6,
            'interval_km'        => 10000,
        ]);

        $res = $this->getJson('/api/interval-perawatan');

        $res->assertStatus(200);
        $row = $res->json('data')[0];
        $this->assertSame('CDD', $row['nama_jenis_kendaraan']);
        $this->assertSame('Tiap 10.000 km / 6 bulan', $row['label']);
        $this->assertSame([], $row['sparepart']);
    }

    public function test_update_interval_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_bulan'     => 6,
            'interval_km'        => 10000,
        ])->json('data.id_interval_perawatan');

        $res = $this->putJson("/api/interval-perawatan/{$id}", ['interval_bulan' => 8]);

        $res->assertStatus(200)->assertJsonPath('data.interval_bulan', 8);
    }

    public function test_update_menolak_duplikat_kombinasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKendaraan = $this->makeJenisKendaraan();
        $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_bulan' => 6, 'interval_km' => 10000,
        ])->assertStatus(201);
        $idB = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $idKendaraan, 'interval_bulan' => 24, 'interval_km' => 40000,
        ])->json('data.id_interval_perawatan');

        $res = $this->putJson("/api/interval-perawatan/{$idB}", ['interval_bulan' => 6, 'interval_km' => 10000]);

        $res->assertStatus(422);
    }

    public function test_update_menolak_keduanya_dikosongkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(), 'interval_bulan' => 6, 'interval_km' => 10000,
        ])->json('data.id_interval_perawatan');

        $res = $this->putJson("/api/interval-perawatan/{$id}", ['interval_km' => null, 'interval_bulan' => null]);

        $res->assertStatus(422)->assertJsonPath('message', 'Isi minimal interval kilometer atau interval bulan');
    }

    public function test_hapus_interval_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/interval-perawatan', [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'interval_bulan'     => 6,
            'interval_km'        => 10000,
        ])->json('data.id_interval_perawatan');

        $this->deleteJson("/api/interval-perawatan/{$id}")->assertStatus(200);

        $row = DB::table('interval_perawatan')->where('id_interval_perawatan', $id)->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = $this->makePerusahaanLain();
        $id = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $id,
            'id_perusahaan'         => $lain,
            'id_jenis_kendaraan'    => $this->makeJenisKendaraan('CDD', $lain),
            'interval_bulan'        => 6,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);

        $this->assertCount(0, $this->getJson('/api/interval-perawatan')->json('data'));
        $this->getJson("/api/interval-perawatan/{$id}")->assertStatus(404);
        $this->putJson("/api/interval-perawatan/{$id}", ['interval_bulan' => 1])->assertStatus(404);
        $this->deleteJson("/api/interval-perawatan/{$id}")->assertStatus(404);
    }
}
