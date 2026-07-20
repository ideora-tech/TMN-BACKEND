<?php
// tests/Feature/PaketPerawatanSparepartTest.php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaketPerawatanSparepartTest extends TestCase
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

    private function makeSparepart(string $nama = 'Oli Mesin Diesel 15W-40', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan, 'kode' => 'SP-' . Str::random(6),
            'nama' => $nama, 'satuan' => 'liter', 'harga_standar' => 60000, 'stok' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_paket_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'id_sparepart'       => $this->makeSparepart(),
            'qty_standar'        => 6,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.qty_standar', 6)
            ->assertJsonPath('data.nama_jenis_perawatan', 'Ganti Oli Mesin')
            ->assertJsonPath('data.nama_jenis_kendaraan', 'CDD')
            ->assertJsonPath('data.nama_sparepart', 'Oli Mesin Diesel 15W-40');
    }

    public function test_menolak_tanpa_field_wajib(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/paket-perawatan-sparepart', []);

        $res->assertStatus(422)->assertJsonValidationErrors(['id_jenis_perawatan', 'id_jenis_kendaraan', 'id_sparepart', 'qty_standar']);
    }

    public function test_menolak_duplikat_kombinasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();
        $idSparepart = $this->makeSparepart();

        $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idSparepart, 'qty_standar' => 6,
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idSparepart, 'qty_standar' => 10,
        ]);

        $res->assertStatus(422);
    }

    public function test_menolak_referensi_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();

        $res = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan('Ganti Oli', $lain),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'id_sparepart'       => $this->makeSparepart(),
            'qty_standar'        => 6,
        ]);

        $res->assertStatus(404);
    }

    public function test_update_dan_hapus_paket(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'id_sparepart'       => $this->makeSparepart(),
            'qty_standar'        => 6,
        ])->json('data.id_paket_perawatan_sparepart');

        $this->putJson("/api/v1/paket-perawatan-sparepart/{$id}", ['qty_standar' => 8])
            ->assertStatus(200)->assertJsonPath('data.qty_standar', 8);

        $this->deleteJson("/api/v1/paket-perawatan-sparepart/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('paket_perawatan_sparepart', ['id_paket_perawatan_sparepart' => $id]);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();
        $id = (string) Str::uuid();
        DB::table('paket_perawatan_sparepart')->insert([
            'id_paket_perawatan_sparepart' => $id,
            'id_perusahaan'                => $lain,
            'id_jenis_perawatan'           => $this->makeJenisPerawatan('Ganti Oli', $lain),
            'id_jenis_kendaraan'           => $this->makeJenisKendaraan('CDD', $lain),
            'id_sparepart'                 => $this->makeSparepart('Oli', $lain),
            'qty_standar'                  => 6,
            'aktif'                        => 1,
            'dibuat_pada'                  => now(),
        ]);

        $this->assertCount(0, $this->getJson('/api/v1/paket-perawatan-sparepart')->json('data'));
        $this->getJson("/api/v1/paket-perawatan-sparepart/{$id}")->assertStatus(404);
        $this->putJson("/api/v1/paket-perawatan-sparepart/{$id}", ['qty_standar' => 1])->assertStatus(404);
        $this->deleteJson("/api/v1/paket-perawatan-sparepart/{$id}")->assertStatus(404);
    }

    public function test_paket_difilter_saat_sparepart_soft_deleted(): void
    {
        $this->actingAsRole('ADMIN');

        $idSparepart = $this->makeSparepart('Oli Mesin');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();

        $createRes = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis,
            'id_jenis_kendaraan' => $idKendaraan,
            'id_sparepart'       => $idSparepart,
            'qty_standar'        => 5,
        ]);
        $paketId = $createRes->json('data.id_paket_perawatan_sparepart');
        $createRes->assertStatus(201)->assertJsonPath('data.nama_sparepart', 'Oli Mesin');

        $listBefore = $this->getJson('/api/v1/paket-perawatan-sparepart')->json('data');
        $this->assertCount(1, $listBefore);

        DB::table('sparepart')->where('id_sparepart', $idSparepart)->update(['dihapus_pada' => now()]);

        $listAfter = $this->getJson('/api/v1/paket-perawatan-sparepart')->json('data');
        $this->assertCount(0, $listAfter);

        $detailRes = $this->getJson("/api/v1/paket-perawatan-sparepart/{$paketId}");
        $detailRes->assertStatus(404);
    }

    public function test_paket_difilter_saat_jenis_perawatan_soft_deleted(): void
    {
        $this->actingAsRole('ADMIN');

        $idJenis = $this->makeJenisPerawatan('Ganti Oli Mesin');
        $idKendaraan = $this->makeJenisKendaraan();
        $idSparepart = $this->makeSparepart();

        $createRes = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis,
            'id_jenis_kendaraan' => $idKendaraan,
            'id_sparepart'       => $idSparepart,
            'qty_standar'        => 4,
        ]);
        $paketId = $createRes->json('data.id_paket_perawatan_sparepart');
        $createRes->assertStatus(201)->assertJsonPath('data.nama_jenis_perawatan', 'Ganti Oli Mesin');

        $listBefore = $this->getJson('/api/v1/paket-perawatan-sparepart')->json('data');
        $this->assertCount(1, $listBefore);

        DB::table('jenis_perawatan')->where('id_jenis_perawatan', $idJenis)->update(['dihapus_pada' => now()]);

        $listAfter = $this->getJson('/api/v1/paket-perawatan-sparepart')->json('data');
        $this->assertCount(0, $listAfter);

        $detailRes = $this->getJson("/api/v1/paket-perawatan-sparepart/{$paketId}");
        $detailRes->assertStatus(404);
    }
}
