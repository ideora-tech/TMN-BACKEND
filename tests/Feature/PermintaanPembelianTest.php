<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermintaanPembelianTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeSupplier(): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert(['id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => 'Toko ATK Jaya', 'aktif' => 1, 'dibuat_pada' => now()]);
        return $id;
    }

    private function makeBarang(string $nama = 'Kertas A4', string $satuan = 'rim'): string
    {
        $id = (string) Str::uuid();
        DB::table('barang')->insert([
            'id_barang' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'BRG-' . Str::random(4),
            'nama' => $nama, 'satuan' => $satuan, 'harga_standar' => 50000, 'stok' => 0, 'stok_minimum' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payloadPr(array $override = []): array
    {
        return array_merge([
            'judul' => 'ATK Oktober', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 55000],
                ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000],
            ],
        ], $override);
    }

    private function buatPr(string $peran = 'DISPATCHER', array $override = []): array
    {
        $this->actingAsRole($peran);
        return $this->postJson('/api/permintaan-pembelian', $this->payloadPr($override))->assertStatus(201)->json('data');
    }

    private function prSampaiDibeli(): array
    {
        $pr = $this->buatPr();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        $res = $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 52000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 350000],
            ],
        ])->assertStatus(200);
        return $res->json('data');
    }

    public function test_create_tanpa_approval_langsung_disetujui_dan_nomor_pr(): void
    {
        $pr = $this->buatPr();
        $this->assertMatchesRegularExpression('/^PR-\d{6}-\d{4}$/', $pr['nomor_permintaan']);
        $this->assertSame('disetujui', $pr['status']);
        $this->assertSame(850000.0, (float) $pr['total_estimasi']);
        $this->assertSame('Kertas A4', $pr['items'][0]['nama_barang']);
    }

    public function test_create_mengirim_notifikasi_ke_pengadaan(): void
    {
        $idPengadaan = $this->actingAsRole('PENGADAAN')->id_pengguna;
        $idSuperadmin = $this->actingAsRole('SUPERADMIN')->id_pengguna;
        $idSupir = $this->actingAsRole('SUPIR')->id_pengguna;
        $idManager = $this->actingAsRole('MANAGER')->id_pengguna;
        $idKeuangan = $this->actingAsRole('KEUANGAN')->id_pengguna;
        $idSalesLain = $this->actingAsRole('SALES')->id_pengguna;
        $pr = $this->buatPr('SALES');
        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $idPengadaan, 'tipe' => 'permintaan_pembelian', 'referensi_id' => $pr['id_permintaan']]);
        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $idSuperadmin, 'tipe' => 'permintaan_pembelian', 'referensi_id' => $pr['id_permintaan']]);
        $this->assertSame('PENGADAAN', DB::table('pengguna')->where('id_pengguna', $idPengadaan)->value('kode_peran'));
        foreach ([$idSupir, $idManager, $idKeuangan, $idSalesLain, $pr['id_pengaju']] as $idBukanPengadaan) {
            $this->assertDatabaseMissing('notifikasi', ['id_pengguna' => $idBukanPengadaan, 'tipe' => 'permintaan_pembelian', 'referensi_id' => $pr['id_permintaan']]);
        }
        $this->assertSame(2, DB::table('notifikasi')->where('referensi_id', $pr['id_permintaan'])->where('judul', "PR {$pr['nomor_permintaan']} siap diproses")->count());
    }

    public function test_validasi_item_wajib_dan_jenis_valid(): void
    {
        $this->actingAsRole('DISPATCHER');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPr(['items' => []]))->assertStatus(422);
        $this->postJson('/api/permintaan-pembelian', $this->payloadPr(['items' => [['jenis' => 'sparepart', 'nama_item' => 'X', 'qty' => 1, 'satuan' => 'pcs', 'harga_estimasi' => 1]]]))->assertStatus(422);
    }

    public function test_edit_dan_hapus_hanya_oleh_pengaju_atau_kelola(): void
    {
        $pr = $this->buatPr('DISPATCHER');
        $this->actingAsRole('SALES');
        $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", $this->payloadPr(['judul' => 'Ubah']))->assertStatus(422);
        $this->deleteJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(422);
        $this->actingAsRole('ADMIN');
        $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", $this->payloadPr(['judul' => 'Ubah Admin']))->assertStatus(200)->assertJsonPath('data.judul', 'Ubah Admin');
        $this->deleteJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(404);
    }

    public function test_list_filter_status_milik_saya_dan_ringkasan(): void
    {
        $this->buatPr('DISPATCHER');
        $this->buatPr('SALES', ['judul' => 'Punya Sales']);
        $res = $this->getJson('/api/permintaan-pembelian?milik_saya=1')->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Punya Sales', $res->json('data.0.judul'));
        $this->assertSame(2, $res->json('meta.ringkasan.disetujui'));
        $this->assertCount(2, $this->getJson('/api/permintaan-pembelian?status=disetujui')->json('data'));
    }

    public function test_tenant_lain_404(): void
    {
        $pr = $this->buatPr();
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'PT Lain', 'aktif' => 1, 'dibuat_pada' => now()]);
        $idPengguna = (string) auth()->id();
        DB::table('pengguna')->where('id_pengguna', $idPengguna)->update(['id_perusahaan' => $lain]);
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::findOrFail($idPengguna), ['*']);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(404);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(404);
    }

    public function test_role_tanpa_izin_403(): void
    {
        $this->actingAsRole('SUPIR');
        $this->getJson('/api/permintaan-pembelian')->assertStatus(403);
    }
}
