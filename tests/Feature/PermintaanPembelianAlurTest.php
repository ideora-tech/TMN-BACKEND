<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermintaanPembelianAlurTest extends TestCase
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

    public function test_proses_dan_dibeli_hanya_pengadaan(): void
    {
        $pr = $this->buatPr('DISPATCHER');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(422);
        $this->actingAsRole('ADMIN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(422);
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200)->assertJsonPath('data.status', 'diproses');
    }

    public function test_dibeli_validasi_bukti_supplier_dan_tautan_barang(): void
    {
        $pr = $this->buatPr('DISPATCHER', ['items' => [['jenis' => 'barang', 'nama_item' => 'Pulpen Bebas', 'qty' => 5, 'satuan' => 'pcs', 'harga_estimasi' => 3000]]]);
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $payload = ['id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => now()->toDateString(), 'items' => [['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 2500]]];
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(422);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(422)->assertJsonPath('message', 'Item "Pulpen Bebas" belum ditautkan ke Master Barang');
        $idBarang = $this->postJson('/api/barang/buat-cepat', ['nama' => 'Pulpen', 'satuan' => 'pcs'])->assertStatus(201)->json('data.id_barang');
        $payload['items'][0]['id_barang'] = $idBarang;
        $res = $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200);
        $this->assertSame('dibeli', $res->json('data.status'));
        $this->assertSame(12500.0, (float) $res->json('data.total_aktual'));
        $this->assertSame('Pulpen', $res->json('data.items.0.nama_item'));
        $this->assertSame(2500.0, (float) DB::table('barang')->where('id_barang', $idBarang)->value('harga_standar'));
    }

    public function test_terima_menambah_stok_barang_dan_mengabaikan_jasa(): void
    {
        $pr = $this->prSampaiDibeli();
        $idBarang = $pr['items'][0]['id_barang'];
        $this->actingAsRole('SALES');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", ['tanggal_diterima' => now()->toDateString(), 'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 1]]])->assertStatus(422);
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 11], ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 1]],
        ])->assertStatus(422);
        $res = $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'keterangan' => '2 rim rusak, ditolak',
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 8], ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 1]],
        ])->assertStatus(200);
        $this->assertSame('diterima', $res->json('data.status'));
        $this->assertSame('2 rim rusak, ditolak', $res->json('data.keterangan_penerimaan'));
        $this->assertDatabaseHas('permintaan_pembelian', ['id_permintaan' => $pr['id_permintaan'], 'keterangan_penerimaan' => '2 rim rusak, ditolak']);
        $this->assertSame(8, (int) DB::table('barang')->where('id_barang', $idBarang)->value('stok'));
        $this->assertDatabaseHas('barang_mutasi', ['id_barang' => $idBarang, 'jenis' => 'masuk', 'qty' => 8, 'id_permintaan_pembelian' => $pr['id_permintaan']]);
        $this->assertSame(1, DB::table('barang_mutasi')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
    }

    public function test_pengaju_boleh_konfirmasi_penerimaan(): void
    {
        $pr = $this->prSampaiDibeli();
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::findOrFail($pr['id_pengaju']), ['*']);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 10], ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 1]],
        ])->assertStatus(200)->assertJsonPath('data.status', 'diterima');
    }

    public function test_terima_oleh_pengadaan_memberi_notifikasi_ke_pengaju_bukan_ke_manager(): void
    {
        $idManager = $this->actingAsRole('MANAGER')->id_pengguna;
        $idPengadaanLain = $this->actingAsRole('PENGADAAN')->id_pengguna;
        $pr = $this->prSampaiDibeli();
        $idPelaku = $this->actingAsRole('PENGADAAN')->id_pengguna;
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 10], ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 1]],
        ])->assertStatus(200);
        $judul = "PR {$pr['nomor_permintaan']} sudah diterima";
        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $pr['id_pengaju'], 'judul' => $judul, 'referensi_id' => $pr['id_permintaan']]);
        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $idPengadaanLain, 'judul' => $judul, 'referensi_id' => $pr['id_permintaan']]);
        $this->assertDatabaseMissing('notifikasi', ['id_pengguna' => $idPelaku, 'judul' => $judul, 'referensi_id' => $pr['id_permintaan']]);
        $this->assertDatabaseMissing('notifikasi', ['id_pengguna' => $idManager, 'judul' => $judul, 'referensi_id' => $pr['id_permintaan']]);
    }

    public function test_terima_oleh_pengaju_hanya_memberi_notifikasi_ke_pengadaan(): void
    {
        $idPengadaanLain = $this->actingAsRole('PENGADAAN')->id_pengguna;
        $pr = $this->prSampaiDibeli();
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::findOrFail($pr['id_pengaju']), ['*']);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 10], ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 1]],
        ])->assertStatus(200);
        $judul = "PR {$pr['nomor_permintaan']} sudah diterima";
        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $idPengadaanLain, 'judul' => $judul, 'referensi_id' => $pr['id_permintaan']]);
        $this->assertDatabaseMissing('notifikasi', ['id_pengguna' => $pr['id_pengaju'], 'judul' => $judul, 'referensi_id' => $pr['id_permintaan']]);
    }

    public function test_batal_oleh_pengaju_hanya_sebelum_diproses(): void
    {
        $pr = $this->buatPr('DISPATCHER');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/batal", ['alasan' => 'Tidak jadi'])->assertStatus(200)->assertJsonPath('data.status', 'dibatalkan');
        $pr2 = $this->buatPr('DISPATCHER');
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr2['id_permintaan']}/proses")->assertStatus(200);
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::findOrFail($pr2['id_pengaju']), ['*']);
        $this->patchJson("/api/permintaan-pembelian/{$pr2['id_permintaan']}/batal", ['alasan' => 'Telat'])->assertStatus(422);
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr2['id_permintaan']}/batal", ['alasan' => 'Supplier kosong'])->assertStatus(200);
        $pr3 = $this->prSampaiDibeli();
        $this->patchJson("/api/permintaan-pembelian/{$pr3['id_permintaan']}/batal", ['alasan' => 'X'])->assertStatus(422);
    }
}
