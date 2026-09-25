<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PermintaanPembelianSparepartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeSparepart(string $nama = 'Filter Oli', float $hargaStandar = 100000, int $stok = 2, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode' => 'SP-' . Str::random(6), 'nama' => $nama, 'satuan' => 'pcs',
            'harga_standar' => $hargaStandar, 'stok' => $stok, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSupplier(string $nama = 'Toko Onderdil', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
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

    private function makeTenantLain(): array
    {
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $pengguna = \App\Models\Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        return [$idLain, $pengguna];
    }

    private function berikanIzinUbahPembelianSparepart(string $kodePeran): void
    {
        $idMenu = DB::table('menu')->where('path', '/pembelian-sparepart')->value('id_menu');
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => $kodePeran,
            'id_menu' => $idMenu, 'aksi' => 'ubah', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function payloadPrSparepart(array $override = []): array
    {
        return array_merge([
            'judul' => 'Stok spare part bulanan', 'tipe' => 'sparepart', 'alasan' => 'Stok menipis',
            'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart('Filter Oli', 100000), 'qty' => 2, 'harga_estimasi' => 100000],
                ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart('Kampas Rem', 200000), 'qty' => 1, 'harga_estimasi' => 200000, 'spesifikasi' => 'Depan'],
            ],
            'bukti' => [UploadedFile::fake()->image('penawaran.jpg')],
        ], $override);
    }

    private function buatPrSparepart(string $peran = 'DISPATCHER', array $override = []): array
    {
        $this->actingAsRole($peran);
        return $this->postJson('/api/permintaan-pembelian', $this->payloadPrSparepart($override))->assertStatus(201)->json('data');
    }

    private function prosesPr(string $idPermintaan): array
    {
        $this->actingAsRole('PENGADAAN');
        return $this->patchJson("/api/permintaan-pembelian/{$idPermintaan}/proses")->assertStatus(200)->json('data');
    }

    private function psDariPr(array $pr): object
    {
        $ps = DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->assertNotNull($ps);
        $ps->items = DB::table('pembelian_sparepart_item')->where('id_pembelian', $ps->id_pembelian)->whereNull('dihapus_pada')->get()->all();
        return $ps;
    }

    private function realisasiPs(object $ps, string $peran, array $hargaPerItemPr, array $extra = []): TestResponse
    {
        $this->actingAsRole($peran);
        $items = [];
        foreach ($ps->items as $item) {
            $items[] = ['id_item' => $item->id_item, 'harga_aktual' => $hargaPerItemPr[$item->id_item_permintaan]];
        }
        return $this->patchJson("/api/pembelian-sparepart/{$ps->id_pembelian}/realisasi", array_merge([
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => $items,
        ], $extra));
    }

    private function prSampaiDiterima(): array
    {
        $pr = $this->buatPrSparepart();
        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $harga = [$pr['items'][0]['id_item'] => 90000, $pr['items'][1]['id_item'] => 210000];
        $idSupplier = $this->makeSupplier();
        $this->realisasiPs($ps, 'PENGADAAN', $harga, ['id_supplier' => $idSupplier])->assertStatus(200);
        return [$pr, $ps, $idSupplier];
    }

    public function test_validasi_tipe_dan_jenis_item(): void
    {
        $this->actingAsRole('DISPATCHER');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrSparepart(['items' => [
            ['jenis' => 'sparepart', 'qty' => 1, 'harga_estimasi' => 1000],
        ]]))->assertStatus(422)->assertJsonPath('message', 'Item PR spare part wajib dipilih dari master Spare Part');

        $this->postJson('/api/permintaan-pembelian', $this->payloadPrSparepart(['items' => [
            ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 1000],
            ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas', 'qty' => 1, 'satuan' => 'rim', 'harga_estimasi' => 1000],
        ]]))->assertStatus(422)->assertJsonPath('message', 'PR tipe spare part tidak boleh dicampur barang atau jasa');

        $this->postJson('/api/permintaan-pembelian', $this->payloadPrSparepart(['tipe' => 'umum', 'items' => [
            ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 1000],
        ]]))->assertStatus(422)->assertJsonPath('message', 'Item spare part hanya untuk PR tipe spare part');

        [$idLain] = $this->makeTenantLain();
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrSparepart(['items' => [
            ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart('Asing', 1000, 0, $idLain), 'qty' => 1, 'harga_estimasi' => 1000],
        ]]))->assertStatus(422)->assertJsonPath('message', 'Spare part tidak ditemukan di perusahaan Anda');

        $tanpaTipe = $this->payloadPrSparepart();
        unset($tanpaTipe['tipe']);
        $this->postJson('/api/permintaan-pembelian', $tanpaTipe)->assertStatus(422);
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrSparepart(['tipe' => 'lainnya']))->assertStatus(422);

        $this->assertSame(0, DB::table('permintaan_pembelian')->count());
    }

    public function test_buat_pr_sparepart_mengambil_nama_dan_satuan_dari_master(): void
    {
        $pr = $this->buatPrSparepart();
        $this->assertSame('sparepart', $pr['tipe']);
        $this->assertSame('disetujui', $pr['status']);
        $this->assertSame(400000.0, (float) $pr['total_estimasi']);
        $this->assertSame('Filter Oli', $pr['items'][0]['nama_item']);
        $this->assertSame('pcs', $pr['items'][0]['satuan']);
        $this->assertSame('sparepart', $pr['items'][0]['jenis']);
        $this->assertNotNull($pr['items'][0]['id_sparepart']);
        $this->assertNotNull($pr['items'][0]['kode_sparepart']);
        $this->assertSame('Filter Oli', $pr['items'][0]['nama_sparepart']);
        $this->assertSame(2, $pr['items'][0]['stok_sparepart']);
        $this->assertNull($pr['items'][0]['id_barang']);
        $this->assertSame('Depan', $pr['items'][1]['spesifikasi']);
        $this->assertNull($pr['pembelian_sparepart']);

        $this->actingAsRole('PENGADAAN');
        $this->getJson('/api/permintaan-pembelian?tipe=sparepart')->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/permintaan-pembelian?tipe=umum')->assertStatus(200)->assertJsonPath('meta.total', 0);
    }

    public function test_proses_pr_sparepart_melahirkan_ps_disetujui_finance(): void
    {
        $pr = $this->buatPrSparepart();
        $buktiPr = DB::table('permintaan_pembelian_bukti')->where('id_permintaan', $pr['id_permintaan'])->first();
        $hasil = $this->prosesPr($pr['id_permintaan']);

        $this->assertSame('diproses', $hasil['status']);
        $ps = $this->psDariPr($pr);
        $this->assertSame('disetujui_finance', $ps->status);
        $this->assertSame($pr['id_permintaan'], $ps->id_permintaan_pembelian);
        $this->assertNull($ps->id_supplier);
        $this->assertSame(400000.0, (float) $ps->total_estimasi);
        $this->assertSame("Dari {$pr['nomor_permintaan']}: Stok spare part bulanan", $ps->keterangan);
        $this->assertNotNull($ps->disetujui_finance_pada);

        $this->assertSame($ps->id_pembelian, $hasil['pembelian_sparepart']['id_pembelian']);
        $this->assertSame($ps->nomor_pengajuan, $hasil['pembelian_sparepart']['nomor_pengajuan']);
        $this->assertSame('disetujui_finance', $hasil['pembelian_sparepart']['status']);

        $this->assertCount(2, $ps->items);
        $idItemPr = array_column($pr['items'], 'id_item');
        foreach ($ps->items as $item) {
            $this->assertContains($item->id_item_permintaan, $idItemPr);
        }
        $this->assertSame($pr['items'][0]['id_sparepart'], collect($ps->items)->firstWhere('id_item_permintaan', $pr['items'][0]['id_item'])->id_sparepart);

        $buktiPs = DB::table('pembelian_sparepart_bukti')->where('id_pembelian', $ps->id_pembelian)->get();
        $this->assertCount(1, $buktiPs);
        $this->assertSame($buktiPr->url_file, $buktiPs[0]->url_file);
        $this->assertSame('penawaran.jpg', $buktiPs[0]->nama_asli);

        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->where('id_pembelian', $ps->id_pembelian)->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $pr['id_pengaju'],
            'judul'       => "PR {$pr['nomor_permintaan']} sedang diproses Pengadaan (PS {$ps->nomor_pengajuan})",
        ]);

        $this->getJson("/api/pembelian-sparepart/{$ps->id_pembelian}")->assertStatus(200)
            ->assertJsonPath('data.nomor_permintaan', $pr['nomor_permintaan'])
            ->assertJsonPath('data.id_permintaan_pembelian', $pr['id_permintaan']);
    }

    public function test_dibeli_terima_dan_bukti_tahap_pembelian_ditolak_untuk_pr_sparepart(): void
    {
        $pr = $this->buatPrSparepart();
        $pr = $this->prosesPr($pr['id_permintaan']);
        $pesan = 'PR spare part ditandai dibeli dan diterima otomatis lewat realisasi Pembelian Sparepart';

        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 1], ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', $pesan);

        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 2], ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', $pesan);

        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])
            ->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'penerimaan', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])
            ->assertStatus(422)->assertJsonPath('message', 'PR spare part diterima otomatis saat realisasi');
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pengajuan', 'bukti' => [UploadedFile::fake()->image('lampiran.jpg')]])
            ->assertStatus(200);

        $this->assertSame('diproses', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
    }

    public function test_realisasi_ps_menutup_pr_sampai_diterima_dan_membuat_pengajuan(): void
    {
        $pr = $this->buatPrSparepart();
        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        $harga = [$pr['items'][0]['id_item'] => 90000, $pr['items'][1]['id_item'] => 210000];
        $stokAwal = (int) DB::table('sparepart')->where('id_sparepart', $pr['items'][0]['id_sparepart'])->value('stok');

        $this->berikanIzinUbahPembelianSparepart('DISPATCHER');
        $this->realisasiPs($ps, 'DISPATCHER', $harga)
            ->assertStatus(422)->assertJsonPath('message', 'Realisasi pembelian dari PR hanya bisa dilakukan tim Pengadaan');
        $this->assertSame('diproses', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));

        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $idSupplier = $this->makeSupplier('Toko Onderdil');
        $this->realisasiPs($ps, 'PENGADAAN', $harga, ['id_supplier' => $idSupplier])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.id_supplier', $idSupplier)
            ->assertJsonPath('data.total_aktual', 390000);

        $prDb = DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
        $this->assertSame('diterima', $prDb->status);
        $this->assertSame($idSupplier, $prDb->id_supplier);
        $this->assertSame(390000.0, (float) $prDb->total_aktual);
        $this->assertSame(now()->toDateString(), $prDb->tanggal_pembelian);
        $this->assertSame(now()->toDateString(), $prDb->tanggal_diterima);
        $this->assertNotNull($prDb->dibeli_oleh);
        $this->assertNotNull($prDb->diterima_pada);
        $this->assertSame("Diterima otomatis via realisasi {$ps->nomor_pengajuan}", $prDb->keterangan_penerimaan);

        $item0 = DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][0]['id_item'])->first();
        $item1 = DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][1]['id_item'])->first();
        $this->assertSame(90000.0, (float) $item0->harga_aktual);
        $this->assertSame(2, (int) $item0->qty_diterima);
        $this->assertSame(210000.0, (float) $item1->harga_aktual);
        $this->assertSame(1, (int) $item1->qty_diterima);

        $this->assertSame($stokAwal + 2, (int) DB::table('sparepart')->where('id_sparepart', $pr['items'][0]['id_sparepart'])->value('stok'));

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->assertNotNull($pengajuan);
        $this->assertSame('pengadaan', $pengajuan->kategori);
        $this->assertSame($ps->id_pembelian, $pengajuan->id_pembelian);
        $this->assertSame(390000.0, (float) $pengajuan->nominal);
        $this->assertSame('Toko Onderdil', $pengajuan->penerima);
        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_pembelian', $ps->id_pembelian)->count());

        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $pr['id_pengaju'], 'judul' => "PR {$pr['nomor_permintaan']} sudah dibeli & diterima"]);

        $this->actingAsRole('PENGADAAN');
        $detail = $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->json('data');
        $this->assertSame('diterima', $detail['status']);
        $this->assertSame('dibeli', $detail['pembelian_sparepart']['status']);
        $this->assertSame($pengajuan->nomor_pengajuan, $detail['pengajuan_keuangan']['nomor_pengajuan']);
        $this->getJson("/api/pembelian-sparepart/{$ps->id_pembelian}")->assertStatus(200)
            ->assertJsonPath('data.pengajuan_keuangan.nomor_pengajuan', $pengajuan->nomor_pengajuan);
    }

    public function test_transfer_pengajuan_membuat_ps_lunas_dan_pr_selesai(): void
    {
        [$pr, $ps] = $this->prSampaiDiterima();
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $pengajuan->id_pengajuan)->update(['status' => 'siap_transfer']);

        $this->actingAsRole('KEUANGAN');
        $this->getJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/rincian-sumber")->assertStatus(200)
            ->assertJsonPath('data.tipe', 'permintaan_pembelian')
            ->assertJsonPath('data.data.nomor_permintaan', $pr['nomor_permintaan']);

        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ])->assertStatus(200);

        $this->assertSame('lunas', DB::table('pembelian_sparepart')->where('id_pembelian', $ps->id_pembelian)->value('status'));
        $this->assertSame(now()->toDateString(), DB::table('pembelian_sparepart')->where('id_pembelian', $ps->id_pembelian)->value('tanggal_pembayaran'));
        $this->assertSame('selesai', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
        $this->assertSame(now()->toDateString(), DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('tanggal_pembayaran'));
    }

    public function test_batal_pr_saat_diproses_menolak_ps_dan_ditolak_setelah_realisasi(): void
    {
        $pr = $this->buatPrSparepart();
        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/batal", ['alasan' => 'Anggaran ditunda'])
            ->assertStatus(200)->assertJsonPath('data.status', 'dibatalkan')->assertJsonPath('data.pembelian_sparepart.status', 'ditolak');
        $psDb = DB::table('pembelian_sparepart')->where('id_pembelian', $ps->id_pembelian)->first();
        $this->assertSame('ditolak', $psDb->status);
        $this->assertSame('PR dibatalkan: Anggaran ditunda', $psDb->alasan_ditolak);

        [$pr2, $ps2] = $this->prSampaiDiterima();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr2['id_permintaan']}/batal", ['alasan' => 'Terlambat'])->assertStatus(422);
        $this->assertSame('diterima', DB::table('permintaan_pembelian')->where('id_permintaan', $pr2['id_permintaan'])->value('status'));
        $this->assertSame('dibeli', DB::table('pembelian_sparepart')->where('id_pembelian', $ps2->id_pembelian)->value('status'));
    }

    public function test_update_dan_delete_ps_dari_pr_ditolak(): void
    {
        $pr = $this->buatPrSparepart();
        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        $pesan = 'Pembelian yang lahir dari PR tidak bisa diubah atau dihapus, batalkan PR-nya';
        $this->actingAsRole('SUPERADMIN');
        $this->putJson("/api/pembelian-sparepart/{$ps->id_pembelian}", [
            'tanggal_pengajuan' => now()->toDateString(),
            'items' => [['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 1000]],
        ])->assertStatus(422)->assertJsonPath('message', $pesan);
        $this->deleteJson("/api/pembelian-sparepart/{$ps->id_pembelian}")->assertStatus(422)->assertJsonPath('message', $pesan);
        $this->assertNull(DB::table('pembelian_sparepart')->where('id_pembelian', $ps->id_pembelian)->value('dihapus_pada'));
    }

    public function test_pr_dan_ps_tenant_lain_tidak_bisa_diakses(): void
    {
        $pr = $this->buatPrSparepart();
        [, $penggunaLain] = $this->makeTenantLain();
        \Laravel\Sanctum\Sanctum::actingAs($penggunaLain, ['*']);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(404);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(404);
        $this->assertSame('disetujui', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));

        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        \Laravel\Sanctum\Sanctum::actingAs($penggunaLain, ['*']);
        $this->getJson("/api/pembelian-sparepart/{$ps->id_pembelian}")->assertStatus(404);
        $this->assertCount(0, $this->getJson('/api/pembelian-sparepart?limit=50')->assertStatus(200)->json('data'));
        $this->assertCount(0, $this->getJson('/api/permintaan-pembelian?limit=50')->assertStatus(200)->json('data'));
    }

    public function test_ps_dari_pr_tanpa_bukti_wajib_unggah_nota_sebelum_realisasi(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER', ['bukti' => []]);
        $this->assertSame(0, DB::table('permintaan_pembelian_bukti')->where('id_permintaan', $pr['id_permintaan'])->count());
        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        $this->assertSame(0, DB::table('pembelian_sparepart_bukti')->where('id_pembelian', $ps->id_pembelian)->count());

        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $harga = [$pr['items'][0]['id_item'] => 90000, $pr['items'][1]['id_item'] => 210000];
        $this->realisasiPs($ps, 'PENGADAAN', $harga)
            ->assertStatus(422)->assertJsonPath('message', 'Unggah minimal 1 bukti nota sebelum realisasi');
        $this->assertSame('diproses', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$ps->id_pembelian}/bukti", ['bukti' => [UploadedFile::fake()->image('nota.jpg')]])
            ->assertStatus(200);
        $this->assertSame(1, DB::table('pembelian_sparepart_bukti')->where('id_pembelian', $ps->id_pembelian)->whereNull('dihapus_pada')->count());

        $this->realisasiPs($ps, 'PENGADAAN', $harga)->assertStatus(200)->assertJsonPath('data.status', 'dibeli');
        $prDb = DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
        $this->assertSame('diterima', $prDb->status);
        $this->assertNull($prDb->id_supplier);
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->assertNotNull($pengajuan);
        $this->assertSame('-', $pengajuan->penerima);
        $this->assertSame($pr['nomor_permintaan'], $pengajuan->keterangan);
    }

    public function test_hapus_bukti_ps_dari_pr_tidak_menghapus_berkas_bersama_pr(): void
    {
        $pr = $this->buatPrSparepart();
        $buktiPr = DB::table('permintaan_pembelian_bukti')->where('id_permintaan', $pr['id_permintaan'])->first();
        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        $buktiPs = DB::table('pembelian_sparepart_bukti')->where('id_pembelian', $ps->id_pembelian)->first();
        $this->assertSame($buktiPr->url_file, $buktiPs->url_file);
        Storage::disk('public')->assertExists($buktiPr->url_file);

        $this->actingAsRole('SUPERADMIN');
        $this->deleteJson("/api/pembelian-sparepart/{$ps->id_pembelian}/bukti/{$buktiPs->id_bukti}")
            ->assertStatus(200)->assertJsonCount(0, 'data.bukti');

        $this->assertNotNull(DB::table('pembelian_sparepart_bukti')->where('id_bukti', $buktiPs->id_bukti)->value('dihapus_pada'));
        $this->assertNull(DB::table('permintaan_pembelian_bukti')->where('id_bukti', $buktiPr->id_bukti)->value('dihapus_pada'));
        Storage::disk('public')->assertExists($buktiPr->url_file);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->assertJsonCount(1, 'data.bukti');
    }

    public function test_update_pr_sparepart_sebelum_diproses_bisa_ganti_tipe_umum(): void
    {
        $pr = $this->buatPrSparepart();
        $this->actingAsRole('SUPERADMIN');
        $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", [
            'judul' => 'ATK kantor', 'tipe' => 'umum', 'alasan' => 'Ganti kebutuhan',
            'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'sparepart', 'id_sparepart' => $pr['items'][0]['id_sparepart'], 'qty' => 1, 'harga_estimasi' => 1000],
            ],
        ])->assertStatus(422)->assertJsonPath('message', 'Item spare part hanya untuk PR tipe spare part');

        $hasil = $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", [
            'judul' => 'ATK kantor', 'tipe' => 'umum', 'alasan' => 'Ganti kebutuhan',
            'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas', 'qty' => 3, 'satuan' => 'rim', 'harga_estimasi' => 50000],
            ],
        ])->assertStatus(200)->json('data');

        $this->assertSame('umum', $hasil['tipe']);
        $this->assertSame('disetujui', $hasil['status']);
        $this->assertSame(150000.0, (float) $hasil['total_estimasi']);
        $this->assertCount(1, $hasil['items']);
        $this->assertSame('barang', $hasil['items'][0]['jenis']);
        $this->assertNull($hasil['items'][0]['id_sparepart']);
        $this->assertSame(0, DB::table('permintaan_pembelian_item')->where('id_permintaan', $pr['id_permintaan'])->whereNull('dihapus_pada')->where('jenis', 'sparepart')->count());

        $diproses = $this->prosesPr($pr['id_permintaan']);
        $this->assertSame('diproses', $diproses['status']);
        $this->assertNull($diproses['pembelian_sparepart']);
        $this->assertSame(0, DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
    }

    public function test_realisasi_ps_tenant_lain_oleh_pengadaan_ditolak_404(): void
    {
        $pr = $this->buatPrSparepart();
        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        $harga = [$pr['items'][0]['id_item'] => 90000, $pr['items'][1]['id_item'] => 210000];

        [$idLain] = $this->makeTenantLain();
        $pengadaanLain = \App\Models\Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'PENGADAAN',
            'username' => 'pengadaan_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'PENGADAAN',
            'id_menu' => DB::table('menu')->where('path', '/pembelian-sparepart')->value('id_menu'),
            'aksi' => 'ubah', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($pengadaanLain, ['*']);

        $items = [];
        foreach ($ps->items as $item) {
            $items[] = ['id_item' => $item->id_item, 'harga_aktual' => $harga[$item->id_item_permintaan]];
        }
        $this->patchJson("/api/pembelian-sparepart/{$ps->id_pembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(), 'items' => $items,
        ])->assertStatus(404);

        $this->assertSame('disetujui_finance', DB::table('pembelian_sparepart')->where('id_pembelian', $ps->id_pembelian)->value('status'));
        $this->assertSame('diproses', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
        $this->assertNull(DB::table('pembelian_sparepart_item')->where('id_pembelian', $ps->id_pembelian)->whereNotNull('harga_aktual')->first());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
    }

    private function makePerawatan(?string $idPerusahaan = null, string $nopol = 'B 9876 PR'): array
    {
        $armada = \App\Modules\Armada\ArmadaModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $idPerawatan,
            'id_armada'       => $armada->id_armada,
            'tanggal'         => '2026-03-15',
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => 'selesai',
            'dibuat_pada'     => now(),
        ]);
        return [$idPerawatan, (string) $armada->id_armada];
    }

    public function test_pr_sparepart_tertaut_perawatan_diwariskan_ke_ps_dan_muncul_di_detail_perawatan(): void
    {
        [$idPerawatan, $idArmada] = $this->makePerawatan();
        $pr = $this->buatPrSparepart('DISPATCHER', ['id_perawatan' => $idPerawatan]);

        $this->assertSame($idPerawatan, $pr['id_perawatan']);
        $this->assertSame('B 9876 PR', $pr['nopol_perawatan']);
        $this->assertSame('2026-03-15', $pr['tanggal_perawatan']);
        $this->assertSame($idPerawatan, DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('id_perawatan'));

        $pr = $this->prosesPr($pr['id_permintaan']);
        $ps = $this->psDariPr($pr);
        $this->assertSame($idPerawatan, $ps->id_perawatan);

        $this->actingAsRole('SUPERADMIN');
        $detail = $this->getJson("/api/armada/{$idArmada}/perawatan/{$idPerawatan}")->assertStatus(200)->json('data');
        $this->assertCount(1, $detail['pembelian']);
        $this->assertSame($ps->id_pembelian, $detail['pembelian'][0]['id_pembelian']);
        $this->assertCount(1, $detail['permintaan_pembelian']);
        $this->assertSame($pr['id_permintaan'], $detail['permintaan_pembelian'][0]['id_permintaan']);
        $this->assertSame($pr['nomor_permintaan'], $detail['permintaan_pembelian'][0]['nomor_permintaan']);
        $this->assertSame('diproses', $detail['permintaan_pembelian'][0]['status']);
        $this->assertSame('sparepart', $detail['permintaan_pembelian'][0]['tipe']);
        $this->assertSame(400000.0, (float) $detail['permintaan_pembelian'][0]['total_estimasi']);
    }

    public function test_pr_sparepart_dengan_perawatan_tenant_lain_ditolak_422(): void
    {
        [$idLain] = $this->makeTenantLain();
        [$idPerawatanLain] = $this->makePerawatan($idLain, 'B 1 LAIN');

        $this->actingAsRole('DISPATCHER');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrSparepart(['id_perawatan' => $idPerawatanLain]))
            ->assertStatus(422)->assertJsonPath('message', 'Perawatan armada tidak ditemukan');
        $this->assertSame(0, DB::table('permintaan_pembelian')->count());
    }

    public function test_pr_tipe_umum_mengabaikan_id_perawatan(): void
    {
        [$idPerawatan] = $this->makePerawatan();

        $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'ATK kantor', 'tipe' => 'umum', 'alasan' => 'Habis', 'id_perawatan' => $idPerawatan,
            'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas', 'qty' => 1, 'satuan' => 'rim', 'harga_estimasi' => 50000],
            ],
        ])->assertStatus(201)->json('data');

        $this->assertNull($pr['id_perawatan']);
        $this->assertNull($pr['nopol_perawatan']);
        $this->assertNull(DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('id_perawatan'));
    }
}
