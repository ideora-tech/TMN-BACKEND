<?php
// tests/Feature/PaketPerawatanSparepartResolusiTest.php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaketPerawatanSparepartResolusiTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisPerawatan(string $nama = 'Ganti Oli Mesin', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id, 'id_perusahaan' => $idPerusahaan, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $nama = 'CDD', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_jenis' => strtoupper($nama) . '-' . Str::random(4), 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama, string $idPerusahaan = self::PERUSAHAAN_ID, int $aktif = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan, 'kode' => 'SP-' . Str::random(6),
            'nama' => $nama, 'satuan' => 'liter', 'harga_standar' => 60000, 'stok' => 0, 'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_resolusi_mengembalikan_daftar_part_untuk_kombinasi_cocok(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();
        $idOli = $this->makeSparepart('Oli Mesin Diesel 15W-40');
        $idFilter = $this->makeSparepart('Filter Oli');

        $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idOli, 'qty_standar' => 6,
        ])->assertStatus(201);
        $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idFilter, 'qty_standar' => 1,
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/paket-perawatan-sparepart/resolusi?id_jenis_perawatan={$idJenis}&id_jenis_kendaraan={$idKendaraan}");

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('Filter Oli', $res->json('data.0.nama_sparepart'));
        $this->assertSame(6, $res->json('data.1.qty_standar'));
    }

    public function test_resolusi_tanpa_kombinasi_cocok_mengembalikan_array_kosong(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/paket-perawatan-sparepart/resolusi?id_jenis_perawatan=' . $this->makeJenisPerawatan()
            . '&id_jenis_kendaraan=' . $this->makeJenisKendaraan());

        $res->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_resolusi_mengecualikan_sparepart_yang_nonaktif(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();
        $idNonaktif = $this->makeSparepart('Sparepart Nonaktif', self::PERUSAHAAN_ID, 0);

        $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idNonaktif, 'qty_standar' => 1,
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/paket-perawatan-sparepart/resolusi?id_jenis_perawatan={$idJenis}&id_jenis_kendaraan={$idKendaraan}");

        $res->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_resolusi_menolak_tanpa_query_wajib(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/paket-perawatan-sparepart/resolusi');

        $res->assertStatus(422);
    }
}
