<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MenerbitkanPo;
use Tests\TestCase;

class PermintaanPembelianPenerimaanSebagianTest extends TestCase
{
    use RefreshDatabase;
    use MenerbitkanPo;

    private const TOTAL_PO = 889130.0;

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

    private function makeBarang(): string
    {
        $id = (string) Str::uuid();
        DB::table('barang')->insert([
            'id_barang' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'BRG-' . Str::random(4),
            'nama' => 'Kertas A4', 'satuan' => 'rim', 'harga_standar' => 50000, 'stok' => 0, 'stok_minimum' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function prSampaiDibeli(): array
    {
        $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'ATK Oktober', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 55000],
                ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000],
            ],
        ])->assertStatus(201)->json('data');

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $payload = [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 52000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 350000],
            ],
            'diskon' => 87000, 'ppn_persen' => 11, 'ongkir' => 20000,
        ];
        $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200)->json('data');
    }

    private function terima(array $pr, int $qtyBarang, int $qtyJasa, string $peran = 'PENGADAAN', ?string $keterangan = null): TestResponse
    {
        $this->actingAsRole($peran);
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", array_filter([
            'tanggal_diterima' => now()->toDateString(),
            'keterangan'       => $keterangan,
            'items'            => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => $qtyBarang],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => $qtyJasa],
            ],
        ], fn ($v) => $v !== null));
    }

    private function tutupSisa(array $pr, array $payload, string $peran = 'PENGADAAN'): TestResponse
    {
        $this->actingAsRole($peran);
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/tutup-sisa", $payload);
    }

    private function pengajuan(array $pr): object
    {
        return DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
    }

    private function transfer(array $pr): TestResponse
    {
        $pengajuan = $this->pengajuan($pr);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $pengajuan->id_pengajuan)->update(['status' => 'siap_transfer']);
        $this->actingAsRole('KEUANGAN');
        return $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ]);
    }

    private function header(array $pr): object
    {
        return DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
    }

    private function stokBarang(array $pr): int
    {
        return (int) DB::table('barang')->where('id_barang', $pr['items'][0]['id_barang'])->value('stok');
    }

    public function test_penerimaan_bertahap_menahan_pembayaran_sampai_lengkap(): void
    {
        $pr = $this->prSampaiDibeli();
        $this->assertSame(self::TOTAL_PO, (float) $pr['total_aktual']);

        $this->terima($pr, 0, 0)->assertStatus(422)->assertJsonPath('message', 'Isi jumlah yang diterima minimal untuk satu item');
        $this->terima($pr, 11, 1)->assertStatus(422)->assertJsonPath('message', 'Qty diterima "Kertas A4" melebihi qty permintaan');

        $res = $this->terima($pr, 4, 0, 'PENGADAAN', 'Sisanya menyusul minggu depan')->assertStatus(200);
        $res->assertJsonPath('data.status', 'diterima_sebagian')
            ->assertJsonPath('message', 'Penerimaan sebagian dicatat, stok diperbarui')
            ->assertJsonPath('data.items.0.qty_diterima', 4)
            ->assertJsonPath('data.items.1.qty_diterima', 0)
            ->assertJsonCount(1, 'data.penerimaan')
            ->assertJsonCount(1, 'data.penerimaan.0.items')
            ->assertJsonPath('data.penerimaan.0.items.0.qty', 4)
            ->assertJsonPath('data.penerimaan.0.keterangan', 'Sisanya menyusul minggu depan');
        $this->assertSame(4, $this->stokBarang($pr));
        $this->assertSame(self::TOTAL_PO, (float) $this->pengajuan($pr)->nominal);

        $this->transfer($pr)->assertStatus(409)
            ->assertJsonPath('message', 'Barang/jasa pada PR baru diterima sebagian — transfer menunggu penerimaan lengkap atau sisanya ditutup');
        $this->assertSame('diterima_sebagian', $this->header($pr)->status);

        $this->terima($pr, 7, 1)->assertStatus(422)
            ->assertJsonPath('message', 'Qty diterima "Kertas A4" melebihi sisa yang belum diterima (6)');
        $this->assertSame(4, $this->stokBarang($pr));
        $this->assertSame(1, DB::table('permintaan_pembelian_penerimaan')->where('id_permintaan', $pr['id_permintaan'])->count());

        $pengaju = Pengguna::where('id_pengguna', $pr['id_pengaju'])->firstOrFail();
        Sanctum::actingAs($pengaju, ['*']);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 6],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 1],
            ],
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('message', 'Penerimaan dikonfirmasi, stok diperbarui')
            ->assertJsonPath('data.items.0.qty_diterima', 10)
            ->assertJsonPath('data.items.1.qty_diterima', 1)
            ->assertJsonCount(2, 'data.penerimaan');
        $this->assertSame(10, $this->stokBarang($pr));
        $this->assertSame(2, DB::table('barang_mutasi')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
        $this->assertNull($this->header($pr)->sisa_ditutup_pada);
        $this->assertSame(self::TOTAL_PO, (float) $this->header($pr)->total_aktual);

        $this->terima($pr, 1, 0)->assertStatus(422);
        $this->tutupSisa($pr, ['alasan' => 'Tidak ada sisa'])->assertStatus(422);

        $this->transfer($pr)->assertStatus(200);
        $this->assertSame('selesai', $this->header($pr)->status);
        $this->assertSame(self::TOTAL_PO, (float) $this->pengajuan($pr)->nominal);
    }

    public function test_tutup_sisa_menurunkan_pembayaran_sesuai_yang_diterima(): void
    {
        $pr = $this->prSampaiDibeli();
        $this->tutupSisa($pr, ['alasan' => 'Belum ada yang datang'])->assertStatus(422);
        $this->terima($pr, 4, 1)->assertStatus(200)->assertJsonPath('data.status', 'diterima_sebagian');

        $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis'], 'DISPATCHER')->assertStatus(422)
            ->assertJsonPath('message', 'Aksi ini hanya bisa dilakukan oleh tim Pengadaan');
        $this->tutupSisa($pr, [])->assertStatus(422)->assertJsonValidationErrors(['alasan']);
        $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis', 'diskon' => 558001])->assertStatus(422)
            ->assertJsonPath('message', 'Diskon tidak boleh melebihi nilai barang yang diterima (Rp 558.000)');
        $this->assertSame('diterima_sebagian', $this->header($pr)->status);
        $this->assertSame(self::TOTAL_PO, (float) $this->pengajuan($pr)->nominal);

        $res = $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis'])->assertStatus(200);
        $res->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.alasan_tutup_sisa', 'Stok supplier habis')
            ->assertJsonPath('data.items.0.qty', 10)
            ->assertJsonPath('data.items.0.qty_diterima', 4);
        $this->assertSame(208000.0, (float) $res->json('data.items.0.subtotal_aktual'));
        $this->assertSame(558000.0, (float) $res->json('data.subtotal_aktual'));
        $this->assertSame(55800.0, (float) $res->json('data.diskon'));
        $this->assertSame(55242.0, (float) $res->json('data.ppn'));
        $this->assertSame(20000.0, (float) $res->json('data.ongkir'));
        $this->assertSame(577442.0, (float) $res->json('data.total_aktual'));

        $header = $this->header($pr);
        $this->assertNotNull($header->sisa_ditutup_pada);
        $this->assertSame(577442.0, (float) $header->total_aktual);
        $pengajuan = $this->pengajuan($pr);
        $this->assertSame(577442.0, (float) $pengajuan->nominal);
        $this->assertSame('disetujui', $pengajuan->status);
        $this->assertSame(4, $this->stokBarang($pr));

        $this->terima($pr, 6, 0)->assertStatus(422);
        $this->tutupSisa($pr, ['alasan' => 'Tutup lagi'])->assertStatus(422);
        $this->assertSame(4, $this->stokBarang($pr));

        $this->actingAsRole('PENGADAAN');
        $this->get("/api/permintaan-pembelian/{$pr['id_permintaan']}/po/pdf")->assertStatus(200);

        $this->transfer($pr)->assertStatus(200);
        $this->assertSame('selesai', $this->header($pr)->status);
        $this->assertSame(577442.0, (float) $this->pengajuan($pr)->nominal);
    }

    public function test_tutup_sisa_boleh_mengoreksi_diskon_dan_ongkir(): void
    {
        $pr = $this->prSampaiDibeli();
        $this->terima($pr, 4, 1)->assertStatus(200);

        $res = $this->tutupSisa($pr, ['alasan' => 'Sisa dibatalkan supplier', 'diskon' => 0, 'ongkir' => 0])->assertStatus(200);
        $this->assertSame(0.0, (float) $res->json('data.diskon'));
        $this->assertSame(61380.0, (float) $res->json('data.ppn'));
        $this->assertSame(0.0, (float) $res->json('data.ongkir'));
        $this->assertSame(619380.0, (float) $res->json('data.total_aktual'));
        $this->assertSame(619380.0, (float) $this->pengajuan($pr)->nominal);
    }

    public function test_penerimaan_dan_tutup_sisa_dari_halaman_basi_ditolak(): void
    {
        $pr = $this->prSampaiDibeli();
        $this->terima($pr, 4, 0)->assertStatus(200);

        $this->actingAsRole('PENGADAAN');
        $payload = [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 4, 'qty_sebelumnya' => 0],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 0, 'qty_sebelumnya' => 0],
            ],
        ];
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", $payload)->assertStatus(409)
            ->assertJsonPath('message', 'Penerimaan PR ini baru saja dicatat pengguna lain — muat ulang halaman lalu periksa sisanya');
        $this->assertSame(4, $this->stokBarang($pr));
        $this->assertSame(1, DB::table('permintaan_pembelian_penerimaan')->where('id_permintaan', $pr['id_permintaan'])->count());

        $payload['items'][0]['qty_sebelumnya'] = 4;
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", $payload)->assertStatus(200)
            ->assertJsonPath('data.status', 'diterima_sebagian')
            ->assertJsonPath('data.items.0.qty_diterima', 8);
        $this->assertSame(8, $this->stokBarang($pr));

        $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis', 'jumlah_diterima' => 4])->assertStatus(409)
            ->assertJsonPath('message', 'Penerimaan PR ini baru saja dicatat pengguna lain — muat ulang halaman lalu periksa sisanya');
        $this->assertSame('diterima_sebagian', $this->header($pr)->status);
        $this->assertSame(self::TOTAL_PO, (float) $this->pengajuan($pr)->nominal);

        $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis', 'jumlah_diterima' => 8])->assertStatus(200)
            ->assertJsonPath('data.status', 'diterima');
    }

    public function test_tutup_sisa_saat_pengajuan_ditolak_tetap_menyamakan_nominal(): void
    {
        $pr = $this->prSampaiDibeli();
        $this->terima($pr, 4, 1)->assertStatus(200);
        DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->update(['status' => 'ditolak']);

        $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis'])->assertStatus(200)
            ->assertJsonPath('message', 'Sisa ditutup — pengajuan pembayarannya berstatus ditolak, ajukan ulang pembayarannya')
            ->assertJsonPath('data.status', 'diterima');
        $pengajuan = $this->pengajuan($pr);
        $this->assertSame('ditolak', $pengajuan->status);
        $this->assertSame(577442.0, (float) $pengajuan->nominal);
        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
    }

    public function test_tutup_sisa_membuat_ulang_pengajuan_yang_terhapus(): void
    {
        $pr = $this->prSampaiDibeli();
        $this->terima($pr, 4, 1)->assertStatus(200);
        DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->update(['dihapus_pada' => now()]);

        $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis'])->assertStatus(200)
            ->assertJsonPath('message', 'Sisa ditutup, pembayaran disesuaikan');
        $aktif = DB::table('pengajuan_pengeluaran')
            ->where('id_permintaan_pembelian', $pr['id_permintaan'])->whereNull('dihapus_pada')->get();
        $this->assertCount(1, $aktif);
        $this->assertSame(577442.0, (float) $aktif[0]->nominal);
        $this->assertSame('disetujui', $aktif[0]->status);
    }

    public function test_laporan_memuat_pr_diterima_sebagian_dan_nilai_setelah_sisa_ditutup(): void
    {
        $pr = $this->prSampaiDibeli();
        $this->terima($pr, 4, 1)->assertStatus(200);

        $this->actingAsRole('KEUANGAN');
        $json = $this->getJson('/api/permintaan-pembelian/laporan')->assertStatus(200)->json('data');
        $this->assertSame(1, $json['ringkasan']['jumlah']);
        $this->assertEquals(self::TOTAL_PO, $json['ringkasan']['total_aktual']);
        $this->assertEquals(520000.0, collect($json['per_kategori'])->firstWhere('kategori', 'Barang · Tanpa Kategori')['total_aktual']);

        $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis'])->assertStatus(200);

        $this->actingAsRole('KEUANGAN');
        $json = $this->getJson('/api/permintaan-pembelian/laporan')->assertStatus(200)->json('data');
        $this->assertSame(1, $json['ringkasan']['jumlah']);
        $this->assertEquals(577442.0, $json['ringkasan']['total_aktual']);
        $this->assertEquals(208000.0, collect($json['per_kategori'])->firstWhere('kategori', 'Barang · Tanpa Kategori')['total_aktual']);
        $this->assertEquals(350000.0, collect($json['per_kategori'])->firstWhere('kategori', 'Jasa')['total_aktual']);
    }

    public function test_terima_dan_tutup_sisa_pr_perusahaan_lain_404(): void
    {
        $pr = $this->prSampaiDibeli();
        $this->terima($pr, 4, 1)->assertStatus(200);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penggunaLain = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($penggunaLain, ['*']);

        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/tutup-sisa", ['alasan' => 'Bukan milik saya'])->assertStatus(404);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 6],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 0],
            ],
        ])->assertStatus(404);
        $this->assertSame('diterima_sebagian', $this->header($pr)->status);
        $this->assertSame(4, $this->stokBarang($pr));
    }
}
