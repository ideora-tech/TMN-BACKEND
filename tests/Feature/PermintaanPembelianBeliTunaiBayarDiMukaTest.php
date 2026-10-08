<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MenerbitkanPo;
use Tests\TestCase;

class PermintaanPembelianBeliTunaiBayarDiMukaTest extends TestCase
{
    use RefreshDatabase;
    use MenerbitkanPo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 1000000);
    }

    private function makeSupplier(string $nama = 'Toko Online Jaya'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert(['id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now()]);
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

    private function buatPr(): array
    {
        return $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'ATK Oktober', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 55000],
                ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000],
            ],
        ])->assertStatus(201)->json('data');
    }

    private function prDiproses(): array
    {
        $this->actingAsRole('DISPATCHER');
        $pr = $this->buatPr();

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        return $pr;
    }

    private function beliTunai(array $pr, array $ubah = [], ?string $peran = 'PENGADAAN'): TestResponse
    {
        if ($peran !== null) {
            $this->actingAsRole($peran);
        }
        $data = array_merge([
            'tanggal_pembelian' => now()->toDateString(),
            'nama_toko'         => 'Toko Sumber Rejeki',
            'nama_penalang'     => 'Budi Santoso',
            'items'             => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_dibeli' => 6, 'harga_aktual' => 52000],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_dibeli' => 1, 'harga_aktual' => 350000],
            ],
            'bukti'             => [UploadedFile::fake()->image('nota.jpg')],
        ], $ubah);
        return $this->patch("/api/permintaan-pembelian/{$pr['id_permintaan']}/beli-tunai", array_filter($data, fn ($v) => $v !== null), ['Accept' => 'application/json']);
    }

    private function payloadPo(array $pr, ?string $idSupplier = null, array $tambahan = []): array
    {
        return array_merge([
            'id_supplier' => $idSupplier ?? $this->makeSupplier(),
            'tanggal_po'  => now()->toDateString(),
            'items'       => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 50000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 300000],
            ],
        ], $tambahan);
    }

    private function pesan(array $pr, array $payload): TestResponse
    {
        $this->actingAsRole('PENGADAAN');
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/pesan", $payload);
    }

    private function prDipesanDiMuka(array $tambahan = []): array
    {
        $pr = $this->prDiproses();
        $this->pesan($pr, $this->payloadPo($pr, null, array_merge(['syarat_pembayaran' => 'di_muka'], $tambahan)))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dipesan')
            ->assertJsonPath('data.syarat_pembayaran', 'di_muka');
        return $pr;
    }

    private function prLamaDibeliDiMuka(): array
    {
        $pr = $this->prDipesanDiMuka();
        DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->update(['status' => 'dibeli', 'tanggal_pembelian' => now()->toDateString()]);
        return $pr;
    }

    private function pengajuan(array $pr): ?object
    {
        return DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->whereNull('dihapus_pada')->first();
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

    private function terima(array $pr, int $qtyBarang, int $qtyJasa): TestResponse
    {
        $this->actingAsRole('PENGADAAN');
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items'            => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => $qtyBarang],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => $qtyJasa],
            ],
        ]);
    }

    private function tutupSisa(array $pr, array $payload): TestResponse
    {
        $this->actingAsRole('PENGADAAN');
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/tutup-sisa", $payload);
    }

    private function header(array $pr): object
    {
        return DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
    }

    private function stokBarang(array $pr): int
    {
        return (int) DB::table('barang')->where('id_barang', $pr['items'][0]['id_barang'])->value('stok');
    }

    public function test_beli_tunai_langsung_diterima_dan_penggantian_ke_penalang(): void
    {
        $pr = $this->prDiproses();
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertJsonPath('data.batas_beli_tunai', 1000000);

        $res = $this->beliTunai($pr)->assertStatus(200);
        $res->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.metode_pembelian', 'tunai')
            ->assertJsonPath('data.nama_toko', 'Toko Sumber Rejeki')
            ->assertJsonPath('data.nama_penalang', 'Budi Santoso')
            ->assertJsonPath('data.nomor_po', null)
            ->assertJsonPath('data.total_aktual', 662000)
            ->assertJsonPath('data.items.0.qty_diterima', 6)
            ->assertJsonCount(1, 'data.penerimaan')
            ->assertJsonPath('data.penerimaan.0.items.0.qty', 6);

        $this->assertSame(6, $this->stokBarang($pr));
        $this->assertNotNull($this->header($pr)->sisa_ditutup_pada);
        $this->assertSame(1, DB::table('permintaan_pembelian_bukti')->where('id_permintaan', $pr['id_permintaan'])->where('tahap', 'pembelian')->count());

        $pengajuan = $this->pengajuan($pr);
        $this->assertSame(662000.0, (float) $pengajuan->nominal);
        $this->assertSame('Budi Santoso', $pengajuan->penerima);
        $this->assertStringContainsString('Penggantian beli tunai di Toko Sumber Rejeki', (string) $pengajuan->keterangan);

        $this->transfer($pr)->assertStatus(200);
        $this->assertSame('selesai', $this->header($pr)->status);
    }

    public function test_beli_tunai_dengan_supplier_master_dan_semua_item_dibeli_tidak_menutup_sisa(): void
    {
        $pr = $this->prDiproses();
        $idSupplier = $this->makeSupplier();

        $this->beliTunai($pr, [
            'nama_toko'   => null,
            'id_supplier' => $idSupplier,
            'items'       => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_dibeli' => 10, 'harga_aktual' => 50000],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_dibeli' => 1, 'harga_aktual' => 300000],
            ],
        ])->assertStatus(200)
            ->assertJsonPath('data.id_supplier', $idSupplier)
            ->assertJsonPath('data.nama_toko', null)
            ->assertJsonPath('data.total_aktual', 800000);

        $this->assertNull($this->header($pr)->sisa_ditutup_pada);
        $this->assertSame(10, $this->stokBarang($pr));
    }

    public function test_beli_tunai_ditolak_bila_melebihi_batas_tanpa_nota_tanpa_penjual_atau_bukan_pengadaan(): void
    {
        $pr = $this->prDiproses();

        $this->beliTunai($pr, ['items' => [
            ['id_item' => $pr['items'][0]['id_item'], 'qty_dibeli' => 10, 'harga_aktual' => 90000],
            ['id_item' => $pr['items'][1]['id_item'], 'qty_dibeli' => 1, 'harga_aktual' => 350000],
        ]])->assertStatus(422)
            ->assertJsonPath('message', 'Total Rp 1.250.000 melebihi batas pembelian tunai Rp 1.000.000 — terbitkan PO untuk pembelian ini');

        $this->beliTunai($pr, ['bukti' => null])->assertStatus(422);
        $this->beliTunai($pr, ['nama_toko' => null])->assertStatus(422);
        $this->beliTunai($pr, ['nama_penalang' => null])->assertStatus(422);
        $this->beliTunai($pr, ['items' => [
            ['id_item' => $pr['items'][0]['id_item'], 'qty_dibeli' => 11, 'harga_aktual' => 1000],
            ['id_item' => $pr['items'][1]['id_item'], 'qty_dibeli' => 1, 'harga_aktual' => 1000],
        ]])->assertStatus(422)->assertJsonPath('message', 'Jumlah dibeli "Kertas A4" melebihi jumlah yang diminta');
        $this->beliTunai($pr, [], 'DISPATCHER')->assertStatus(422);

        $this->assertSame('diproses', $this->header($pr)->status);
        $this->assertSame(0, $this->stokBarang($pr));
        $this->assertNull($this->pengajuan($pr));
    }

    public function test_beli_tunai_tidak_bisa_setelah_po_terbit(): void
    {
        $pr = $this->prDiproses();
        $this->pesan($pr, $this->payloadPo($pr))->assertStatus(200);

        $this->beliTunai($pr)->assertStatus(422);
        $this->assertSame('dipesan', $this->header($pr)->status);
    }

    public function test_pemohon_boleh_beli_tunai_sendiri_setelah_disetujui_bila_nilai_pr_di_bawah_batas(): void
    {
        $pemohon = $this->actingAsRole('DISPATCHER');
        $pr = $this->buatPr();
        $this->assertSame('disetujui', $pr['status']);
        $this->assertSame(1000000.0, (float) $pr['batas_beli_tunai']);

        $this->beliTunai($pr, [], 'DISPATCHER')->assertStatus(422)
            ->assertJsonPath('message', 'Pembelian tunai dicatat oleh tim Pengadaan, atau oleh pemohonnya sendiri bila nilai PR di bawah batas pembelian tunai');
        $this->beliTunai($pr, [], 'PENGADAAN')->assertStatus(422);

        Sanctum::actingAs($pemohon, ['*']);
        $this->beliTunai($pr, ['nama_penalang' => 'Pemohon Sendiri'], null)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.metode_pembelian', 'tunai');

        $this->assertSame(6, $this->stokBarang($pr));
        $this->assertSame('Pemohon Sendiri', $this->pengajuan($pr)->penerima);
        $this->transfer($pr)->assertStatus(200);
        $this->assertSame('selesai', $this->header($pr)->status);
    }

    public function test_pemohon_tidak_boleh_beli_tunai_sendiri_bila_estimasi_pr_di_atas_batas(): void
    {
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 500000);
        $pemohon = $this->actingAsRole('DISPATCHER');
        $pr = $this->buatPr();

        $this->beliTunai($pr, ['items' => [
            ['id_item' => $pr['items'][0]['id_item'], 'qty_dibeli' => 2, 'harga_aktual' => 50000],
            ['id_item' => $pr['items'][1]['id_item'], 'qty_dibeli' => 0, 'harga_aktual' => 300000],
        ]], null)->assertStatus(422)
            ->assertJsonPath('message', 'Pembelian tunai dicatat oleh tim Pengadaan, atau oleh pemohonnya sendiri bila nilai PR di bawah batas pembelian tunai');

        $this->assertSame('disetujui', $this->header($pr)->status);
        $this->assertSame(0, $this->stokBarang($pr));
        $this->assertSame($pemohon->id_pengguna, $this->header($pr)->id_pengaju);
    }

    public function test_beli_tunai_dua_kali_tidak_menggandakan_stok_maupun_pengajuan(): void
    {
        $pr = $this->prDiproses();
        $this->beliTunai($pr)->assertStatus(200);
        $this->beliTunai($pr)->assertStatus(422);

        $this->assertSame(6, $this->stokBarang($pr));
        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
        $this->assertSame(1, DB::table('permintaan_pembelian_penerimaan')->where('id_permintaan', $pr['id_permintaan'])->count());
    }

    public function test_po_bayar_di_muka_langsung_membuat_pengajuan_dan_otomatis_dibeli_saat_ditransfer(): void
    {
        $pr = $this->prDipesanDiMuka();

        $pengajuan = $this->pengajuan($pr);
        $this->assertNotNull($pengajuan);
        $this->assertSame(800000.0, (float) $pengajuan->nominal);
        $this->assertSame('Toko Online Jaya', $pengajuan->penerima);
        $this->assertStringContainsString('(bayar di muka)', (string) $pengajuan->keterangan);

        $this->actingAsRole('PENGADAAN');
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('tagihan.jpg')]])->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $this->payloadPo($pr, $this->header($pr)->id_supplier, ['tanggal_pembelian' => now()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'PO bayar di muka otomatis menjadi Dibeli setelah ditransfer Keuangan — koreksi harga lewat Revisi PO');

        $this->transfer($pr)->assertStatus(200);
        $header = $this->header($pr);
        $this->assertSame('dibeli', $header->status);
        $this->assertNotNull($header->tanggal_pembayaran);
        $this->assertNotNull($header->tanggal_pembelian);

        $this->pesan($pr, $this->payloadPo($pr, $header->id_supplier))->assertStatus(422);
        $this->getJson('/api/permintaan-pembelian')->assertJsonPath('meta.dibayar_menunggu_barang', 1);

        $this->terima($pr, 10, 1)->assertStatus(200)->assertJsonPath('data.status', 'selesai');
        $this->assertSame(10, $this->stokBarang($pr));
        $this->getJson('/api/permintaan-pembelian')->assertJsonPath('meta.dibayar_menunggu_barang', 0);
    }

    public function test_revisi_po_bayar_di_muka_menyesuaikan_pengajuan_dan_pindah_syarat_menghapusnya(): void
    {
        $pr = $this->prDipesanDiMuka();
        $idPengajuan = $this->pengajuan($pr)->id_pengajuan;
        $supplierLama = $this->header($pr)->id_supplier;
        $supplierBaru = $this->makeSupplier('CV Mitra Baru');

        $this->pesan($pr, $this->payloadPo($pr, $supplierLama, ['tanggal_po' => now()->subDay()->toDateString()]))->assertStatus(200);
        $this->assertSame($idPengajuan, $this->pengajuan($pr)->id_pengajuan);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['status' => 'siap_transfer']);
        $this->pesan($pr, [
            'id_supplier' => $supplierBaru, 'tanggal_po' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 40000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 300000],
            ],
        ])->assertStatus(200)->assertJsonPath('data.syarat_pembayaran', 'di_muka');

        $pengajuan = $this->pengajuan($pr);
        $this->assertNotSame($idPengajuan, $pengajuan->id_pengajuan);
        $this->assertNotSame('siap_transfer', $pengajuan->status);
        $this->assertSame(700000.0, (float) $pengajuan->nominal);
        $this->assertSame('CV Mitra Baru', $pengajuan->penerima);
        $this->assertNotNull(DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('dihapus_pada'));

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ])->assertStatus(404);
        $this->assertSame('dipesan', $this->header($pr)->status);

        $this->pesan($pr, $this->payloadPo($pr, $supplierBaru, ['syarat_pembayaran' => 'setelah_terima']))
            ->assertStatus(200)
            ->assertJsonPath('data.syarat_pembayaran', 'setelah_terima');
        $this->assertNull($this->pengajuan($pr));

        $this->pesan($pr, $this->payloadPo($pr, $supplierBaru, ['syarat_pembayaran' => 'di_muka']))->assertStatus(200);
        $this->assertNotNull($this->pengajuan($pr));
    }

    public function test_revisi_po_mengajukan_ulang_pembayaran_di_muka_yang_ditolak(): void
    {
        $pr = $this->prDipesanDiMuka();
        $pengajuan = $this->pengajuan($pr);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $pengajuan->id_pengajuan)->update(['status' => 'ditolak', 'alasan_ditolak' => 'Harga terlalu tinggi']);

        $this->pesan($pr, [
            'id_supplier' => $this->header($pr)->id_supplier, 'tanggal_po' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 45000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 300000],
            ],
        ])->assertStatus(200);

        $sesudah = $this->pengajuan($pr);
        $this->assertSame($pengajuan->id_pengajuan, $sesudah->id_pengajuan);
        $this->assertNotSame('ditolak', $sesudah->status);
        $this->assertNull($sesudah->alasan_ditolak);
        $this->assertSame(750000.0, (float) $sesudah->nominal);
    }

    public function test_batal_pr_setelah_po_bayar_di_muka_ikut_menghapus_pengajuannya(): void
    {
        $pr = $this->prDipesanDiMuka();
        $this->assertNotNull($this->pengajuan($pr));

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/batal", ['alasan' => 'Tidak jadi dibeli'])->assertStatus(200);

        $this->assertNull($this->pengajuan($pr));
        $this->assertSame('dibatalkan', $this->header($pr)->status);
    }

    public function test_pr_lama_di_muka_ditransfer_saat_diterima_sebagian_lalu_selesai_setelah_lengkap(): void
    {
        $pr = $this->prLamaDibeliDiMuka();
        $this->terima($pr, 4, 1)->assertStatus(200)->assertJsonPath('data.status', 'diterima_sebagian');

        $this->transfer($pr)->assertStatus(200);
        $this->assertSame('diterima_sebagian', $this->header($pr)->status);
        $this->assertNotNull($this->header($pr)->tanggal_pembayaran);

        $this->terima($pr, 6, 0)->assertStatus(200)->assertJsonPath('data.status', 'selesai');
    }

    public function test_pr_lama_di_muka_ditransfer_setelah_barang_lengkap_langsung_selesai(): void
    {
        $pr = $this->prLamaDibeliDiMuka();
        $this->terima($pr, 10, 1)->assertStatus(200)->assertJsonPath('data.status', 'diterima');

        $this->transfer($pr)->assertStatus(200);
        $this->assertSame('selesai', $this->header($pr)->status);
    }

    public function test_bayar_di_muka_tutup_sisa_setelah_dibayar_mencatat_kelebihan_bayar(): void
    {
        $pr = $this->prDipesanDiMuka();
        $this->transfer($pr)->assertStatus(200);
        $this->terima($pr, 4, 1)->assertStatus(200)->assertJsonPath('data.status', 'diterima_sebagian');

        $this->tutupSisa($pr, ['alasan' => 'Stok supplier habis'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.total_aktual', 500000)
            ->assertJsonPath('data.kelebihan_bayar', 300000);

        $pengajuan = $this->pengajuan($pr);
        $this->assertSame('ditransfer', $pengajuan->status);
        $this->assertSame(800000.0, (float) $pengajuan->nominal);
    }

    public function test_bayar_di_muka_barang_tidak_datang_sama_sekali_bisa_ditutup_dengan_kelebihan_bayar_penuh(): void
    {
        $pr = $this->prDipesanDiMuka();
        $this->tutupSisa($pr, ['alasan' => 'Belum dibayar'])->assertStatus(422);

        $this->transfer($pr)->assertStatus(200);

        $this->tutupSisa($pr, ['alasan' => 'Supplier batal kirim'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.total_aktual', 0)
            ->assertJsonPath('data.kelebihan_bayar', 800000);
        $this->assertSame(0, $this->stokBarang($pr));
        $this->assertSame(800000.0, (float) $this->pengajuan($pr)->nominal);
    }

    public function test_tutup_sisa_setelah_dibayar_menolak_nilai_yang_melebihi_pembayaran(): void
    {
        $pr = $this->prDipesanDiMuka(['diskon' => 300000]);
        $this->assertSame(500000.0, (float) $this->pengajuan($pr)->nominal);

        $this->transfer($pr)->assertStatus(200);
        $this->terima($pr, 8, 1)->assertStatus(200)->assertJsonPath('data.status', 'diterima_sebagian');

        $this->tutupSisa($pr, ['alasan' => 'Stok habis', 'diskon' => 0])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nilai setelah sisa ditutup (Rp 700.000) melebihi yang sudah dibayar (Rp 500.000) — periksa diskon dan ongkos kirim');
        $this->assertSame('diterima_sebagian', $this->header($pr)->status);
    }

    public function test_revisi_po_tanpa_syarat_mempertahankan_bayar_di_muka_dan_saringan_menunggu_barang_memilah(): void
    {
        $pr = $this->prDipesanDiMuka();
        $this->transfer($pr)->assertStatus(200);

        $lain = $this->prDipesanDiMuka();
        $this->pesan($lain, $this->payloadPo($lain, $this->header($lain)->id_supplier))
            ->assertStatus(200)
            ->assertJsonPath('data.syarat_pembayaran', 'di_muka');

        $this->getJson('/api/permintaan-pembelian')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.dibayar_menunggu_barang', 1);
        $this->getJson('/api/permintaan-pembelian?menunggu_barang=1')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_permintaan', $pr['id_permintaan']);
    }

    public function test_tanpa_bayar_di_muka_po_tidak_membuat_pengajuan_dan_transfer_menunggu_barang_diterima(): void
    {
        $pr = $this->prDiproses();
        $payload = $this->payloadPo($pr, null, ['tanggal_pembelian' => now()->toDateString()]);
        $this->pesan($pr, $payload)->assertStatus(200)->assertJsonPath('data.syarat_pembayaran', 'setelah_terima');
        $this->assertNull($this->pengajuan($pr));

        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200);

        $this->transfer($pr)->assertStatus(409);
        $this->assertSame('dibeli', $this->header($pr)->status);
        $this->assertNull($this->header($pr)->tanggal_pembayaran);
    }
}
