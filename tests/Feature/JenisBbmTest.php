<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\JenisBbm\JenisBbmModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JenisBbmTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisBbm(string $idPerusahaan, string $nama = 'Solar'): JenisBbmModel
    {
        return JenisBbmModel::create([
            'id_perusahaan' => $idPerusahaan,
            'nama_bbm'      => $nama,
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

    public function test_membuat_jenis_bbm_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/jenis-bbm', [
            'nama_bbm' => 'Solar',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_bbm', 'Solar')
            ->assertJsonPath('data.aktif', true)
            ->assertJsonPath('data.harga_per_liter', null);

        $this->assertDatabaseHas('jenis_bbm', [
            'nama_bbm'      => 'Solar',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_menolak_membuat_jenis_bbm_tanpa_nama(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/jenis-bbm', []);

        $res->assertStatus(422)->assertJsonValidationErrors(['nama_bbm']);
    }

    public function test_list_jenis_bbm_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');

        $this->makeJenisBbm(self::PERUSAHAAN_ID, 'Solar');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $this->makeJenisBbm($idPerusahaanLain, 'Pertalite');

        $res = $this->getJson('/api/v1/jenis-bbm');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Solar', $data[0]['nama_bbm']);
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_show_jenis_bbm_milik_sendiri_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}");

        $res->assertStatus(200)->assertJsonPath('data.id_jenis_bbm', $jenis->id_jenis_bbm);
    }

    public function test_show_jenis_bbm_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $jenisLain = $this->makeJenisBbm($idPerusahaanLain, 'Pertalite');

        $res = $this->getJson("/api/v1/jenis-bbm/{$jenisLain->id_jenis_bbm}");

        $res->assertStatus(404);
    }

    public function test_update_jenis_bbm_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}", [
            'nama_bbm' => 'Solar Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_bbm', 'Solar Diperbarui');
        $this->assertDatabaseHas('jenis_bbm', [
            'id_jenis_bbm' => $jenis->id_jenis_bbm,
            'nama_bbm'     => 'Solar Diperbarui',
        ]);
    }

    public function test_update_jenis_bbm_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $jenisLain = $this->makeJenisBbm($idPerusahaanLain, 'Pertalite');

        $res = $this->putJson("/api/v1/jenis-bbm/{$jenisLain->id_jenis_bbm}", [
            'nama_bbm' => 'Coba Ubah',
        ]);

        $res->assertStatus(404);
    }

    public function test_hapus_jenis_bbm_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('jenis_bbm')->where('id_jenis_bbm', $jenis->id_jenis_bbm)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson('/api/v1/jenis-bbm')->json('data'));
    }

    public function test_hapus_jenis_bbm_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->deleteJson('/api/v1/jenis-bbm/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }

    public function test_tambah_harga_baru_mengubah_harga_efektif(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        // Belum ada harga -> null
        $this->getJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}")
            ->assertJsonPath('data.harga_per_liter', null);

        $res = $this->postJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}/harga", [
            'harga_per_liter' => 6800.50,
            'berlaku_mulai'   => now()->subDays(5)->toDateString(),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.harga_per_liter', 6800.50);

        $show = $this->getJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}");
        $show->assertStatus(200)->assertJsonPath('data.harga_per_liter', 6800.50);

        // Tambah harga baru dengan berlaku_mulai lebih baru -> harga efektif berubah
        $this->postJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}/harga", [
            'harga_per_liter' => 7200.75,
            'berlaku_mulai'   => now()->toDateString(),
        ])->assertStatus(201);

        $show2 = $this->getJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}");
        $show2->assertStatus(200)->assertJsonPath('data.harga_per_liter', 7200.75);
    }

    public function test_harga_berlaku_mulai_besok_belum_jadi_efektif_hari_ini(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        $this->postJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}/harga", [
            'harga_per_liter' => 6800.50,
            'berlaku_mulai'   => now()->toDateString(),
        ])->assertStatus(201);

        $this->postJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}/harga", [
            'harga_per_liter' => 9999.25,
            'berlaku_mulai'   => now()->addDay()->toDateString(),
        ])->assertStatus(201);

        $show = $this->getJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}");
        $show->assertStatus(200)->assertJsonPath('data.harga_per_liter', 6800.50);
    }

    public function test_riwayat_harga_urut_desc_berlaku_mulai(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        $this->postJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}/harga", [
            'harga_per_liter' => 6500,
            'berlaku_mulai'   => now()->subDays(10)->toDateString(),
        ])->assertStatus(201);

        $this->postJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}/harga", [
            'harga_per_liter' => 6800,
            'berlaku_mulai'   => now()->toDateString(),
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}/harga");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals(6800.0, $data[0]['harga_per_liter']);
        $this->assertEquals(6500.0, $data[1]['harga_per_liter']);
    }

    public function test_tambah_harga_untuk_jenis_bbm_tenant_lain_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $jenisLain = $this->makeJenisBbm($idPerusahaanLain, 'Pertalite');

        $res = $this->postJson("/api/v1/jenis-bbm/{$jenisLain->id_jenis_bbm}/harga", [
            'harga_per_liter' => 6800,
            'berlaku_mulai'   => now()->toDateString(),
        ]);

        $res->assertStatus(404);
    }

    public function test_riwayat_harga_untuk_jenis_bbm_tenant_lain_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $jenisLain = $this->makeJenisBbm($idPerusahaanLain, 'Pertalite');

        $res = $this->getJson("/api/v1/jenis-bbm/{$jenisLain->id_jenis_bbm}/harga");

        $res->assertStatus(404);
    }

    public function test_menolak_tambah_harga_tanpa_field_wajib(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        $res = $this->postJson("/api/v1/jenis-bbm/{$jenis->id_jenis_bbm}/harga", []);

        $res->assertStatus(422)->assertJsonValidationErrors(['harga_per_liter', 'berlaku_mulai']);
    }
}
