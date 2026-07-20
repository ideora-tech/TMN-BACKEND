<?php
// tests/Feature/KategoriSparepartTest.php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KategoriSparepartTest extends TestCase
{
    use RefreshDatabase;

    private function makeKategori(string $nama = 'Oli & Pelumas', ?string $idPerusahaan = null): object
    {
        $id = (string) Str::uuid();
        DB::table('kategori_sparepart')->insert([
            'id_kategori_sparepart' => $id,
            'id_perusahaan'         => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'                  => $nama,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);
        return DB::table('kategori_sparepart')->where('id_kategori_sparepart', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_create_kategori_sparepart_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/kategori-sparepart', ['nama' => 'Filter']);

        $res->assertStatus(201)
            ->assertJsonPath('data.nama', 'Filter')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('kategori_sparepart', [
            'nama' => 'Filter', 'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKategori('Milik Sendiri');
        $this->makeKategori('Milik Orang', $this->makePerusahaanLain());

        $res = $this->getJson('/api/v1/kategori-sparepart');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Milik Sendiri', $res->json('data.0.nama'));
    }

    public function test_update_dan_show_kategori_sparepart(): void
    {
        $this->actingAsRole('ADMIN');
        $kategori = $this->makeKategori();

        $resUpdate = $this->putJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}", [
            'nama' => 'Oli & Pelumas Mesin', 'aktif' => false,
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.nama', 'Oli & Pelumas Mesin')
            ->assertJsonPath('data.aktif', false);

        $resShow = $this->getJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}");
        $resShow->assertStatus(200)->assertJsonPath('data.nama', 'Oli & Pelumas Mesin');
    }

    public function test_delete_kategori_sparepart_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $kategori = $this->makeKategori();

        $res = $this->deleteJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}");
        $res->assertStatus(200);

        $this->assertSoftDeleted('kategori_sparepart', ['id_kategori_sparepart' => $kategori->id_kategori_sparepart]);
        $this->getJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}")->assertStatus(404);
    }

    public function test_delete_ditolak_jika_masih_dipakai_sparepart(): void
    {
        $this->actingAsRole('ADMIN');
        $kategori = $this->makeKategori();
        DB::table('sparepart')->insert([
            'id_sparepart'          => (string) Str::uuid(),
            'id_perusahaan'         => self::PERUSAHAAN_ID,
            'id_kategori_sparepart' => $kategori->id_kategori_sparepart,
            'kode'                  => 'SP-KAT-001',
            'nama'                  => 'Oli Mesin Diesel 15W-40',
            'satuan'                => 'liter',
            'harga_standar'         => 60000,
            'stok'                  => 0,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);

        $res = $this->deleteJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}");

        $res->assertStatus(422);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();
        $kategori = $this->makeKategori('Milik Orang', $lain);

        $this->getJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}")->assertStatus(404);
        $this->putJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}", ['nama' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}")->assertStatus(404);
    }
}
