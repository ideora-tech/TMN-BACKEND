<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BarangTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function idKategori(string $nama = 'ATK'): string
    {
        $id = DB::table('kategori_barang')->where('id_perusahaan', self::PERUSAHAAN_ID)->where('nama', $nama)->value('id_kategori_barang');
        if ($id) {
            return (string) $id;
        }
        $id = (string) Str::uuid();
        DB::table('kategori_barang')->insert([
            'id_kategori_barang' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatBarang(array $override = []): array
    {
        $this->actingAsRole('SUPERADMIN');
        return $this->postJson('/api/barang', array_merge([
            'nama' => 'Kertas A4', 'satuan' => 'rim', 'harga_standar' => 55000, 'stok_minimum' => 5,
            'id_kategori_barang' => $this->idKategori(),
        ], $override))->assertStatus(201)->json('data');
    }

    public function test_create_menghasilkan_kode_otomatis_brg(): void
    {
        $b = $this->buatBarang();
        $this->assertMatchesRegularExpression('/^BRG-\d{4}$/', $b['kode']);
        $this->assertSame(0, $b['stok']);
        $this->assertSame('ATK', $b['nama_kategori']);
        $b2 = $this->buatBarang(['nama' => 'Tinta']);
        $this->assertNotSame($b['kode'], $b2['kode']);
    }

    public function test_list_filter_search_dan_stok_menipis(): void
    {
        $this->buatBarang(['nama' => 'Kertas A4']);
        $this->buatBarang(['nama' => 'Spidol', 'stok_minimum' => 0]);
        $res = $this->getJson('/api/barang?search=kertas')->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $res = $this->getJson('/api/barang?stok_menipis=1')->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame(1, $res->json('meta.ringkasan.stok_menipis'));
    }

    public function test_penyesuaian_lalu_pemakaian_mengubah_stok_dan_mencatat_mutasi(): void
    {
        $b = $this->buatBarang();
        $this->postJson("/api/barang/{$b['id_barang']}/penyesuaian", ['stok_baru' => 10, 'keterangan' => 'Saldo awal'])
            ->assertStatus(200)->assertJsonPath('data.stok', 10);
        $this->postJson("/api/barang/{$b['id_barang']}/pemakaian", ['qty' => 4, 'tanggal' => now()->toDateString(), 'pemakai' => 'Divisi Sales'])
            ->assertStatus(200)->assertJsonPath('data.stok', 6)->assertJsonPath('data.stok_menipis', false);
        $this->postJson("/api/barang/{$b['id_barang']}/pemakaian", ['qty' => 7, 'tanggal' => now()->toDateString(), 'pemakai' => 'Divisi Sales'])
            ->assertStatus(422);
        $mutasi = $this->getJson("/api/barang/{$b['id_barang']}/mutasi")->assertStatus(200)->json('data');
        $this->assertCount(2, $mutasi);
        $keluar = collect($mutasi)->firstWhere('jenis', 'keluar');
        $this->assertNotNull($keluar);
        $this->assertSame(4, $keluar['qty']);
        $this->assertSame('Divisi Sales', $keluar['pemakai']);
        $penyesuaian = collect($mutasi)->firstWhere('jenis', 'penyesuaian');
        $this->assertNotNull($penyesuaian);
        $this->assertSame(10, $penyesuaian['qty']);
        $this->assertNull($penyesuaian['pemakai']);
    }

    public function test_pemakaian_di_bawah_minimum_mengirim_notifikasi_stok_menipis(): void
    {
        $pengadaan = $this->actingAsRole('PENGADAAN');
        $sales = $this->actingAsRole('SALES');
        $b = $this->buatBarang(['stok_minimum' => 5]);
        $this->postJson("/api/barang/{$b['id_barang']}/penyesuaian", ['stok_baru' => 6, 'keterangan' => 'Saldo awal'])->assertStatus(200);
        $this->postJson("/api/barang/{$b['id_barang']}/pemakaian", ['qty' => 2, 'tanggal' => now()->toDateString(), 'pemakai' => 'HR'])
            ->assertStatus(200)->assertJsonPath('data.stok_menipis', true);
        $this->assertDatabaseHas('notifikasi', ['tipe' => 'stok_barang', 'referensi_id' => $b['id_barang'], 'id_pengguna' => (string) $pengadaan->id_pengguna]);
        $this->assertDatabaseMissing('notifikasi', ['tipe' => 'stok_barang', 'id_pengguna' => (string) $sales->id_pengguna]);
    }

    public function test_hapus_ditolak_jika_sudah_ada_mutasi(): void
    {
        $b = $this->buatBarang();
        $this->deleteJson("/api/barang/{$b['id_barang']}")->assertStatus(200);
        $b2 = $this->buatBarang(['nama' => 'Tinta']);
        $this->postJson("/api/barang/{$b2['id_barang']}/penyesuaian", ['stok_baru' => 3, 'keterangan' => 'Saldo awal'])->assertStatus(200);
        $this->deleteJson("/api/barang/{$b2['id_barang']}")->assertStatus(422);
    }

    public function test_kategori_crud_dan_guard(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $k = $this->postJson('/api/kategori-barang', ['nama' => 'Dapur'])->assertStatus(201)->json('data');
        $this->postJson('/api/kategori-barang', ['nama' => 'dapur'])->assertStatus(422);
        $this->putJson("/api/kategori-barang/{$k['id_kategori_barang']}", ['nama' => 'Pantry', 'aktif' => false])->assertStatus(200)->assertJsonPath('data.aktif', false);
        $this->buatBarang(['id_kategori_barang' => $k['id_kategori_barang']]);
        $this->deleteJson("/api/kategori-barang/{$k['id_kategori_barang']}")->assertStatus(422);
    }

    public function test_tenant_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'PT Lain', 'aktif' => 1, 'dibuat_pada' => now()]);
        $idBarangLain = (string) Str::uuid();
        DB::table('barang')->insert([
            'id_barang' => $idBarangLain, 'id_perusahaan' => $lain, 'kode' => 'BRG-LAIN',
            'nama' => 'Barang Lain', 'satuan' => 'pcs', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $this->getJson("/api/barang/{$idBarangLain}")->assertStatus(404);
        $this->putJson("/api/barang/{$idBarangLain}", ['nama' => 'X'])->assertStatus(404);
    }

    public function test_role_tanpa_izin_master_barang_403_tapi_pemohon_pr_boleh_lihat(): void
    {
        $b = $this->buatBarang();
        $this->actingAsRole('SUPIR');
        $this->getJson('/api/barang')->assertStatus(403);
        $this->actingAsRole('SALES');
        $this->getJson('/api/barang')->assertStatus(200);
        $this->postJson('/api/barang', ['nama' => 'X'])->assertStatus(403);
        $this->postJson('/api/barang/buat-cepat', ['nama' => 'X', 'satuan' => 'pcs'])->assertStatus(403);
        $this->assertNotNull($b['id_barang']);
    }
}
