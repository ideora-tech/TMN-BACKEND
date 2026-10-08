<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MenerbitkanPo;
use Tests\TestCase;

class PermintaanPembelianPoSebelumBeliTest extends TestCase
{
    use RefreshDatabase;
    use MenerbitkanPo;

    private const PESAN_SUPPLIER_BEDA = 'Supplier berbeda dengan PO — revisi PO lebih dulu bila supplier berganti';
    private const PESAN_TANPA_NOTA = 'Unggah minimal 1 nota pembelian sebelum mencatat realisasi';
    private const BIAYA_PO = ['diskon' => 40000, 'ppn_persen' => 11, 'ongkir' => 15000];
    private const HARGA_NOTA = [90000, 210000];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00'));
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assertAngka(float $harapan, mixed $nilai): void
    {
        $this->assertTrue(is_int($nilai) || is_float($nilai), 'Nilai bukan angka: ' . var_export($nilai, true));
        $this->assertSame($harapan, (float) $nilai);
    }

    private function assertBiaya(object $baris, float $diskon, float $ppnPersen, float $ppn, float $ongkir, float $total): void
    {
        $this->assertSame($diskon, (float) $baris->diskon);
        $this->assertSame($ppnPersen, (float) $baris->ppn_persen);
        $this->assertSame($ppn, (float) $baris->ppn);
        $this->assertSame($ongkir, (float) $baris->ongkir);
        $this->assertSame($total, (float) $baris->total_aktual);
    }

    private function makeSupplier(string $nama = 'Toko ATK Jaya'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
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

    private function makeSparepart(string $nama, float $hargaStandar, int $stok = 2): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'SP-' . Str::random(6), 'nama' => $nama, 'satuan' => 'pcs',
            'harga_standar' => $hargaStandar, 'stok' => $stok, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePenggunaTenantLain(): Pengguna
    {
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
    }

    private function berikanIzinUbahPembelianSparepart(string $kodePeran): void
    {
        $idMenu = DB::table('menu')->where('path', '/pembelian-sparepart')->value('id_menu');
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => $kodePeran,
            'id_menu' => $idMenu, 'aksi' => 'ubah', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function actingAsPengaju(array $pr): Pengguna
    {
        $pengguna = Pengguna::findOrFail($pr['id_pengaju']);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function prUmumDisetujui(): array
    {
        $this->actingAsRole('DISPATCHER');
        return $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'ATK Oktober', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 55000],
                ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000],
            ],
        ])->assertStatus(201)->assertJsonPath('data.status', 'disetujui')->json('data');
    }

    private function prUmumDiproses(): array
    {
        $pr = $this->prUmumDisetujui();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200)->assertJsonPath('data.status', 'diproses');
        return $pr;
    }

    private function payloadUmum(array $pr, string $idSupplier, array $tambahan = []): array
    {
        return array_merge([
            'id_supplier'       => $idSupplier,
            'tanggal_po'        => '2026-10-05',
            'tanggal_pembelian' => '2026-10-06',
            'items'             => array_map(fn ($i) => [
                'id_item'      => $i['id_item'],
                'harga_aktual' => $i['jenis'] === 'barang' ? 52000 : 350000,
            ], $pr['items']),
        ], $tambahan);
    }

    private function tandaiDibeli(array $pr, array $payload): TestResponse
    {
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload);
    }

    private function batal(array $pr, string $alasan): TestResponse
    {
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/batal", ['alasan' => $alasan]);
    }

    private function unggahNota(array $pr): TestResponse
    {
        return $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", [
            'tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ]);
    }

    private function jumlahNota(array $pr): int
    {
        return DB::table('permintaan_pembelian_bukti')->where('id_permintaan', $pr['id_permintaan'])
            ->where('tahap', 'pembelian')->whereNull('dihapus_pada')->count();
    }

    private function header(array $pr): object
    {
        return DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
    }

    private function pengajuan(array $pr): array
    {
        return DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->whereNull('dihapus_pada')->get()->all();
    }

    private function hargaStandarBarang(array $pr): float
    {
        $idBarang = collect($pr['items'])->firstWhere('jenis', 'barang')['id_barang'];
        return (float) DB::table('barang')->where('id_barang', $idBarang)->value('harga_standar');
    }

    private function prSparepartDisetujui(string $peran = 'DISPATCHER'): array
    {
        $this->actingAsRole($peran);
        return $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'Stok spare part bulanan', 'tipe' => 'sparepart', 'alasan' => 'Stok menipis',
            'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart('Filter Oli', 100000), 'qty' => 2, 'harga_estimasi' => 100000],
                ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart('Kampas Rem', 200000), 'qty' => 1, 'harga_estimasi' => 200000],
            ],
            'bukti' => [UploadedFile::fake()->image('penawaran.jpg')],
        ])->assertStatus(201)->assertJsonPath('data.status', 'disetujui')->json('data');
    }

    private function prSparepartDiproses(): array
    {
        $pr = $this->prSparepartDisetujui();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200)->assertJsonPath('data.status', 'diproses');
        return $pr;
    }

    private function itemsRealisasi(array $pr, array $harga = self::HARGA_NOTA): array
    {
        return array_map(fn ($i, $h) => ['id_item' => $i['id_item'], 'harga_aktual' => $h], $pr['items'], $harga);
    }

    private function itemsRealisasiPs(array $pr, array $harga = self::HARGA_NOTA): array
    {
        $hargaPerItemPr = array_combine(array_column($pr['items'], 'id_item'), $harga);
        return array_map(
            fn ($i) => ['id_item' => $i->id_item, 'harga_aktual' => $hargaPerItemPr[$i->id_item_permintaan]],
            $this->itemsPs($this->psDariPr($pr)),
        );
    }

    private function payloadPoSparepart(array $pr, string $idSupplier, array $tambahan = []): array
    {
        return array_merge([
            'id_supplier' => $idSupplier,
            'tanggal_po'  => '2026-10-05',
            'items'       => $this->itemsRealisasi($pr),
        ], $tambahan);
    }

    private function realisasiSparepart(array $pr, array $tambahan = []): TestResponse
    {
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/realisasi-sparepart", array_merge([
            'tanggal_pembelian' => '2026-10-06',
            'items'             => $this->itemsRealisasi($pr),
        ], $tambahan));
    }

    private function psDariPr(array $pr): object
    {
        $ps = DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->assertNotNull($ps);
        return $ps;
    }

    private function itemsPs(object $ps): array
    {
        return DB::table('pembelian_sparepart_item')->where('id_pembelian', $ps->id_pembelian)->whereNull('dihapus_pada')->get()->all();
    }

    private function realisasiPs(array $pr, array $tambahan = []): TestResponse
    {
        $ps = $this->psDariPr($pr);
        return $this->patchJson("/api/pembelian-sparepart/{$ps->id_pembelian}/realisasi", array_merge([
            'tanggal_pembelian' => '2026-10-06',
            'items'             => $this->itemsRealisasiPs($pr),
        ], $tambahan));
    }

    private function pesanJalurLama(array $pr): string
    {
        return "Pembelian ini berasal dari PR {$pr['nomor_permintaan']} — catat realisasinya dari halaman PR tersebut";
    }

    private function stok(array $pr, int $indeks): int
    {
        return (int) DB::table('sparepart')->where('id_sparepart', $pr['items'][$indeks]['id_sparepart'])->value('stok');
    }

    public function test_po_terbit_dari_diproses_tanpa_nota_dan_belum_membuat_pengajuan_maupun_mengubah_harga_standar(): void
    {
        $pr = $this->prUmumDiproses();
        $idSupplier = $this->makeSupplier();
        $payload = $this->payloadUmum($pr, $idSupplier, ['diskon' => 70000, 'ppn_persen' => 11, 'ongkir' => 25000]);
        $urlPdf = "/api/permintaan-pembelian/{$pr['id_permintaan']}/po/pdf";

        $this->tandaiDibeli($pr, $payload)
            ->assertStatus(422)->assertJsonPath('message', 'Tandai Dibeli hanya bisa dilakukan setelah PO diterbitkan (status saat ini: diproses)');
        $this->get($urlPdf)->assertStatus(422)->assertJsonPath('message', 'PO belum tersedia untuk permintaan ini');
        $this->assertSame('diproses', $this->header($pr)->status);

        $pemesan = $this->actingAsRole('PENGADAAN');
        $res = $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(200);

        $res->assertJsonPath('data.status', 'dipesan')
            ->assertJsonPath('data.nomor_po', 'PO-202610-0001')
            ->assertJsonPath('data.tanggal_po', '2026-10-05')
            ->assertJsonPath('data.id_supplier', $idSupplier)
            ->assertJsonPath('data.tanggal_pembelian', null);
        $this->assertAngka(870000, $res->json('data.subtotal_aktual'));
        $this->assertAngka(70000, $res->json('data.diskon'));
        $this->assertAngka(11, $res->json('data.ppn_persen'));
        $this->assertAngka(88000, $res->json('data.ppn'));
        $this->assertAngka(25000, $res->json('data.ongkir'));
        $this->assertAngka(913000, $res->json('data.total_aktual'));
        $this->assertAngka(52000, collect($res->json('data.items'))->firstWhere('jenis', 'barang')['harga_aktual']);
        $this->assertAngka(350000, collect($res->json('data.items'))->firstWhere('jenis', 'jasa')['harga_aktual']);

        $header = $this->header($pr);
        $this->assertSame('dipesan', $header->status);
        $this->assertSame('PO-202610-0001', $header->nomor_po);
        $this->assertSame('2026-10-05', $header->tanggal_po);
        $this->assertSame($idSupplier, $header->id_supplier);
        $this->assertSame($pemesan->id_pengguna, $header->dipesan_oleh);
        $this->assertNotNull($header->dipesan_pada);
        $this->assertNull($header->tanggal_pembelian);
        $this->assertNull($header->dibeli_oleh);
        $this->assertBiaya($header, 70000, 11, 88000, 25000, 913000);

        $this->assertSame(0, DB::table('permintaan_pembelian_bukti')->where('id_permintaan', $pr['id_permintaan'])->count());
        $this->assertSame([], $this->pengajuan($pr));
        $this->assertSame(50000.0, $this->hargaStandarBarang($pr));

        $pdf = $this->get($urlPdf);
        $pdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('PO-202610-0001.pdf', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_revisi_po_saat_dipesan_mengubah_nilai_dengan_nomor_po_tetap(): void
    {
        $pr = $this->prUmumDiproses();
        $supplierAwal = $this->makeSupplier('Toko ATK Jaya');
        $supplierBaru = $this->makeSupplier('Toko ATK Baru');

        $this->terbitkanPo($pr['id_permintaan'], $this->payloadUmum($pr, $supplierAwal))
            ->assertStatus(200)->assertJsonPath('data.status', 'dipesan')->assertJsonPath('data.nomor_po', 'PO-202610-0001');
        $this->assertSame(870000.0, (float) $this->header($pr)->total_aktual);

        $revisi = $this->terbitkanPo($pr['id_permintaan'], $this->payloadUmum($pr, $supplierBaru, [
            'tanggal_po' => '2026-10-07',
            'items'      => array_map(fn ($i) => [
                'id_item'      => $i['id_item'],
                'harga_aktual' => $i['jenis'] === 'barang' ? 50000 : 360000,
            ], $pr['items']),
            'diskon'     => 20000,
            'ppn_persen' => 11,
            'ongkir'     => 10000,
        ]))->assertStatus(200);

        $revisi->assertJsonPath('data.status', 'dipesan')
            ->assertJsonPath('data.nomor_po', 'PO-202610-0001')
            ->assertJsonPath('data.tanggal_po', '2026-10-07')
            ->assertJsonPath('data.id_supplier', $supplierBaru);
        $this->assertAngka(860000, $revisi->json('data.subtotal_aktual'));
        $this->assertAngka(942400, $revisi->json('data.total_aktual'));

        $header = $this->header($pr);
        $this->assertSame('dipesan', $header->status);
        $this->assertSame('PO-202610-0001', $header->nomor_po);
        $this->assertSame('2026-10-07', $header->tanggal_po);
        $this->assertSame($supplierBaru, $header->id_supplier);
        $this->assertBiaya($header, 20000, 11, 92400, 10000, 942400);
        $this->assertSame([], $this->pengajuan($pr));
        $this->assertSame(50000.0, $this->hargaStandarBarang($pr));

        $prLain = $this->prUmumDiproses();
        $this->terbitkanPo($prLain['id_permintaan'], $this->payloadUmum($prLain, $supplierAwal))
            ->assertStatus(200)->assertJsonPath('data.nomor_po', 'PO-202610-0002');
    }

    public function test_tandai_dibeli_dari_dipesan_wajib_nota_dan_supplier_po_lalu_membuat_pengajuan_dan_memperbarui_harga_standar(): void
    {
        $pr = $this->prUmumDiproses();
        $idSupplier = $this->makeSupplier();
        $payloadPo = $this->payloadUmum($pr, $idSupplier, ['diskon' => 70000, 'ppn_persen' => 11, 'ongkir' => 25000]);
        $payloadNota = array_merge($payloadPo, ['ongkir' => 30000]);
        $this->terbitkanPo($pr['id_permintaan'], $payloadPo)->assertStatus(200)->assertJsonPath('data.nomor_po', 'PO-202610-0001');

        $this->tandaiDibeli($pr, $payloadNota)
            ->assertStatus(422)->assertJsonPath('message', 'Unggah minimal 1 nota/PO supplier (tahap pembelian) sebelum menandai dibeli');
        $this->unggahNota($pr)->assertStatus(200)->assertJsonPath('data.status', 'dipesan');

        $this->tandaiDibeli($pr, array_merge($payloadNota, ['id_supplier' => $this->makeSupplier('Toko Lain')]))
            ->assertStatus(422)->assertJsonPath('message', self::PESAN_SUPPLIER_BEDA);

        $header = $this->header($pr);
        $this->assertSame('dipesan', $header->status);
        $this->assertSame($idSupplier, $header->id_supplier);
        $this->assertSame(913000.0, (float) $header->total_aktual);
        $this->assertSame([], $this->pengajuan($pr));
        $this->assertSame(50000.0, $this->hargaStandarBarang($pr));

        $pembeli = $this->actingAsRole('PENGADAAN');
        $res = $this->tandaiDibeli($pr, $payloadNota)->assertStatus(200);

        $res->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.nomor_po', 'PO-202610-0001')
            ->assertJsonPath('data.tanggal_po', '2026-10-05')
            ->assertJsonPath('data.tanggal_pembelian', '2026-10-06')
            ->assertJsonPath('data.id_supplier', $idSupplier);
        $this->assertAngka(918000, $res->json('data.total_aktual'));

        $header = $this->header($pr);
        $this->assertSame('dibeli', $header->status);
        $this->assertSame('PO-202610-0001', $header->nomor_po);
        $this->assertSame($pembeli->id_pengguna, $header->dibeli_oleh);
        $this->assertBiaya($header, 70000, 11, 88000, 30000, 918000);

        $pengajuan = $this->pengajuan($pr);
        $this->assertCount(1, $pengajuan);
        $this->assertSame('pengadaan', $pengajuan[0]->kategori);
        $this->assertSame(918000.0, (float) $pengajuan[0]->nominal);
        $this->assertSame('Toko ATK Jaya', $pengajuan[0]->penerima);
        $this->assertSame(52000.0, $this->hargaStandarBarang($pr));

        $this->terbitkanPo($pr['id_permintaan'], $payloadPo)
            ->assertStatus(422)->assertJsonPath('message', 'PO hanya bisa diterbitkan saat permintaan sedang diproses Pengadaan (status saat ini: dibeli)');
    }

    public function test_pengadaan_boleh_membatalkan_pr_saat_dipesan_sedangkan_pengaju_tidak(): void
    {
        $pr = $this->prUmumDiproses();
        $payload = $this->payloadUmum($pr, $this->makeSupplier());
        $urlPdf = "/api/permintaan-pembelian/{$pr['id_permintaan']}/po/pdf";
        $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(200)->assertJsonPath('data.status', 'dipesan');
        $this->get($urlPdf)->assertStatus(200);

        $this->actingAsPengaju($pr);
        $this->batal($pr, 'Tidak jadi')
            ->assertStatus(422)->assertJsonPath('message', 'Permintaan tidak bisa dibatalkan pada status ini (status saat ini: dipesan)');
        $this->assertSame('dipesan', $this->header($pr)->status);

        $this->actingAsRole('PENGADAAN');
        $this->batal($pr, 'Supplier kehabisan stok')
            ->assertStatus(200)->assertJsonPath('data.status', 'dibatalkan')->assertJsonPath('data.alasan_batal', 'Supplier kehabisan stok');
        $this->assertSame('dibatalkan', $this->header($pr)->status);
        $this->assertSame([], $this->pengajuan($pr));

        $this->get($urlPdf)->assertStatus(422)->assertJsonPath('message', 'PO ini sudah batal bersama permintaannya');

        $this->tandaiDibeli($pr, $payload)
            ->assertStatus(422)->assertJsonPath('message', 'Tandai Dibeli hanya bisa dilakukan setelah PO diterbitkan (status saat ini: dibatalkan)');
        $this->terbitkanPo($pr['id_permintaan'], $payload)
            ->assertStatus(422)->assertJsonPath('message', 'PO hanya bisa diterbitkan saat permintaan sedang diproses Pengadaan (status saat ini: dibatalkan)');
    }

    public function test_terbitkan_po_hanya_oleh_pengadaan_saat_diproses_dan_404_untuk_perusahaan_lain(): void
    {
        $pr = $this->prUmumDisetujui();
        $payload = $this->payloadUmum($pr, $this->makeSupplier());

        $this->actingAsRole('PENGADAAN');
        $this->terbitkanPo($pr['id_permintaan'], $payload)
            ->assertStatus(422)->assertJsonPath('message', 'PO hanya bisa diterbitkan saat permintaan sedang diproses Pengadaan (status saat ini: disetujui)');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);

        $this->actingAsPengaju($pr);
        $this->terbitkanPo($pr['id_permintaan'], $payload)
            ->assertStatus(422)->assertJsonPath('message', 'Aksi ini hanya bisa dilakukan oleh tim Pengadaan');
        $this->actingAsRole('ADMIN');
        $this->terbitkanPo($pr['id_permintaan'], $payload)
            ->assertStatus(422)->assertJsonPath('message', 'Aksi ini hanya bisa dilakukan oleh tim Pengadaan');

        Sanctum::actingAs($this->makePenggunaTenantLain(), ['*']);
        $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(404);

        $header = $this->header($pr);
        $this->assertSame('diproses', $header->status);
        $this->assertNull($header->nomor_po);
        $this->assertNull($header->tanggal_po);
        $this->assertNull($header->id_supplier);
        $this->assertNull($header->total_aktual);

        $this->actingAsRole('PENGADAAN');
        $tanpaWajib = $payload;
        unset($tanpaWajib['id_supplier'], $tanpaWajib['tanggal_po'], $tanpaWajib['tanggal_pembelian']);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/pesan", $tanpaWajib)
            ->assertStatus(422)->assertJsonValidationErrors(['id_supplier', 'tanggal_po']);
        $this->assertNull($this->header($pr)->nomor_po);

        $this->actingAsRole('SUPERADMIN');
        $this->terbitkanPo($pr['id_permintaan'], $payload)
            ->assertStatus(200)->assertJsonPath('data.status', 'dipesan')->assertJsonPath('data.nomor_po', 'PO-202610-0001');
    }

    public function test_pr_sparepart_lewat_pengadaan_wajib_po_dan_realisasi_memakai_grand_total_po(): void
    {
        $pr = $this->prSparepartDiproses();
        $ps = $this->psDariPr($pr);
        $idSupplier = $this->makeSupplier('Toko Onderdil');
        $stokFilter = $this->stok($pr, 0);
        $stokKampas = $this->stok($pr, 1);

        $this->realisasiSparepart($pr)
            ->assertStatus(422)->assertJsonPath('message', 'Terbitkan PO lebih dulu sebelum mencatat realisasi (status saat ini: diproses)');
        $this->assertSame('diproses', $this->header($pr)->status);

        $po = $this->terbitkanPo($pr['id_permintaan'], $this->payloadPoSparepart($pr, $idSupplier, self::BIAYA_PO))->assertStatus(200);
        $po->assertJsonPath('data.status', 'dipesan')->assertJsonPath('data.nomor_po', 'PO-202610-0001')->assertJsonPath('data.id_supplier', $idSupplier);
        $this->assertAngka(390000, $po->json('data.subtotal_aktual'));
        $this->assertAngka(403500, $po->json('data.total_aktual'));
        $this->assertSame('disetujui_finance', $this->psDariPr($pr)->status);
        $this->assertSame($stokFilter, $this->stok($pr, 0));
        $this->assertSame([], $this->pengajuan($pr));

        $this->realisasiSparepart($pr)
            ->assertStatus(422)->assertJsonPath('message', 'Unggah minimal 1 nota pembelian sebelum mencatat realisasi');
        $this->unggahNota($pr)->assertStatus(200)->assertJsonPath('data.status', 'dipesan');

        $this->actingAsPengaju($pr);
        $this->realisasiSparepart($pr)
            ->assertStatus(422)->assertJsonPath('message', 'PR ini sudah dipesan lewat PO — realisasi dicatat oleh tim Pengadaan');
        $this->assertSame('dipesan', $this->header($pr)->status);

        $this->actingAsRole('PENGADAAN');
        $res = $this->realisasiSparepart($pr)->assertStatus(200);
        $res->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.nomor_po', 'PO-202610-0001')
            ->assertJsonPath('data.id_supplier', $idSupplier)
            ->assertJsonPath('data.pembelian_sparepart.status', 'dibeli');
        $this->assertAngka(390000, $res->json('data.subtotal_aktual'));
        $this->assertAngka(403500, $res->json('data.total_aktual'));

        $header = $this->header($pr);
        $this->assertSame('diterima', $header->status);
        $this->assertSame('PO-202610-0001', $header->nomor_po);
        $this->assertSame($idSupplier, $header->id_supplier);
        $this->assertSame('2026-10-06', $header->tanggal_pembelian);
        $this->assertBiaya($header, 40000, 11, 38500, 15000, 403500);

        $psDb = $this->psDariPr($pr);
        $this->assertSame($ps->id_pembelian, $psDb->id_pembelian);
        $this->assertSame('dibeli', $psDb->status);
        $this->assertSame($idSupplier, $psDb->id_supplier);
        $this->assertBiaya($psDb, 40000, 11, 38500, 15000, 403500);

        $pengajuan = $this->pengajuan($pr);
        $this->assertCount(1, $pengajuan);
        $this->assertSame('pengadaan', $pengajuan[0]->kategori);
        $this->assertSame($ps->id_pembelian, $pengajuan[0]->id_pembelian);
        $this->assertSame(403500.0, (float) $pengajuan[0]->nominal);
        $this->assertSame('Toko Onderdil', $pengajuan[0]->penerima);

        $itemFilter = DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][0]['id_item'])->first();
        $itemKampas = DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][1]['id_item'])->first();
        $this->assertSame(90000.0, (float) $itemFilter->harga_aktual);
        $this->assertSame(2, (int) $itemFilter->qty_diterima);
        $this->assertSame(210000.0, (float) $itemKampas->harga_aktual);
        $this->assertSame(1, (int) $itemKampas->qty_diterima);
        $this->assertEqualsCanonicalizing([90000.0, 210000.0], array_map(fn ($i) => (float) $i->harga_aktual, $this->itemsPs($psDb)));

        $this->assertSame($stokFilter + 2, $this->stok($pr, 0));
        $this->assertSame($stokKampas + 1, $this->stok($pr, 1));
        $this->assertDatabaseHas('sparepart_mutasi', [
            'id_sparepart' => $pr['items'][0]['id_sparepart'], 'id_pembelian' => $ps->id_pembelian, 'jenis' => 'masuk', 'qty' => 2, 'harga' => 90000,
        ]);
    }

    public function test_realisasi_sparepart_lewat_po_menolak_supplier_lain_dan_boleh_mengoreksi_komponen_biaya(): void
    {
        $pr = $this->prSparepartDiproses();
        $idSupplier = $this->makeSupplier('Toko Onderdil');
        $this->terbitkanPo($pr['id_permintaan'], $this->payloadPoSparepart($pr, $idSupplier, self::BIAYA_PO))->assertStatus(200);
        $this->unggahNota($pr)->assertStatus(200);

        $this->realisasiSparepart($pr, ['id_supplier' => $this->makeSupplier('Toko Lain')])
            ->assertStatus(422)->assertJsonPath('message', self::PESAN_SUPPLIER_BEDA);
        $this->assertSame('dipesan', $this->header($pr)->status);

        $this->realisasiSparepart($pr, ['diskon' => 0, 'ppn_persen' => null, 'ongkir' => 20000])
            ->assertStatus(200)->assertJsonPath('data.status', 'diterima')->assertJsonPath('data.id_supplier', $idSupplier);

        $this->assertBiaya($this->psDariPr($pr), 0, 11, 42900, 20000, 452900);
        $this->assertBiaya($this->header($pr), 0, 11, 42900, 20000, 452900);
        $pengajuan = $this->pengajuan($pr);
        $this->assertCount(1, $pengajuan);
        $this->assertSame(452900.0, (float) $pengajuan[0]->nominal);
        $this->assertSame(90000.0, (float) DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][0]['id_item'])->value('harga_aktual'));
    }

    public function test_batal_pr_sparepart_saat_dipesan_menolak_pembelian_sparepart(): void
    {
        $pr = $this->prSparepartDiproses();
        $ps = $this->psDariPr($pr);
        $stokFilter = $this->stok($pr, 0);
        $this->terbitkanPo($pr['id_permintaan'], $this->payloadPoSparepart($pr, $this->makeSupplier('Toko Onderdil')))->assertStatus(200);

        $this->batal($pr, 'Anggaran ditunda')
            ->assertStatus(200)->assertJsonPath('data.status', 'dibatalkan')->assertJsonPath('data.pembelian_sparepart.status', 'ditolak');
        $this->get("/api/permintaan-pembelian/{$pr['id_permintaan']}/po/pdf")
            ->assertStatus(422)->assertJsonPath('message', 'PO ini sudah batal bersama permintaannya');

        $psDb = $this->psDariPr($pr);
        $this->assertSame($ps->id_pembelian, $psDb->id_pembelian);
        $this->assertSame('ditolak', $psDb->status);
        $this->assertSame('PR dibatalkan: Anggaran ditunda', $psDb->alasan_ditolak);
        $this->assertSame($stokFilter, $this->stok($pr, 0));
        $this->assertSame([], $this->pengajuan($pr));

        $this->realisasiSparepart($pr)->assertStatus(422);
        $this->assertSame('dibatalkan', $this->header($pr)->status);
    }

    public function test_pr_sparepart_mandiri_direalisasi_pengaju_tanpa_po(): void
    {
        $pr = $this->prSparepartDisetujui();
        $this->assertTrue($pr['boleh_realisasi_mandiri']);
        $stokFilter = $this->stok($pr, 0);

        $this->actingAsPengaju($pr);
        $this->unggahNota($pr)->assertStatus(200);
        $res = $this->realisasiSparepart($pr, ['ppn_persen' => 11, 'ongkir' => 5000])->assertStatus(200);

        $res->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.nomor_po', null)
            ->assertJsonPath('data.tanggal_po', null)
            ->assertJsonPath('data.pembelian_sparepart.status', 'dibeli');
        $this->assertAngka(437900, $res->json('data.total_aktual'));

        $header = $this->header($pr);
        $this->assertSame('diterima', $header->status);
        $this->assertNull($header->nomor_po);
        $this->assertNull($header->tanggal_po);
        $this->assertNull($header->dipesan_oleh);
        $this->assertNull($header->id_supplier);
        $this->assertBiaya($header, 0, 11, 42900, 5000, 437900);

        $ps = $this->psDariPr($pr);
        $this->assertSame('dibeli', $ps->status);
        $this->assertBiaya($ps, 0, 11, 42900, 5000, 437900);

        $pengajuan = $this->pengajuan($pr);
        $this->assertCount(1, $pengajuan);
        $this->assertSame(437900.0, (float) $pengajuan[0]->nominal);
        $this->assertSame('-', $pengajuan[0]->penerima);
        $this->assertSame($pr['nomor_permintaan'], $pengajuan[0]->keterangan);

        $this->assertSame($stokFilter + 2, $this->stok($pr, 0));
        $this->assertSame(90000.0, (float) DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][0]['id_item'])->value('harga_aktual'));

        $this->get("/api/permintaan-pembelian/{$pr['id_permintaan']}/po/pdf")
            ->assertStatus(422)->assertJsonPath('message', 'PO belum tersedia untuk permintaan ini');
    }

    public function test_realisasi_lewat_endpoint_ps_untuk_ps_dari_pr_selalu_ditolak_dan_jalur_pr_memakai_biaya_po(): void
    {
        $pr = $this->prSparepartDiproses();
        $ps = $this->psDariPr($pr);
        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $idSupplier = $this->makeSupplier('Toko Onderdil');
        $stokFilter = $this->stok($pr, 0);
        $stokKampas = $this->stok($pr, 1);

        $this->actingAsRole('PENGADAAN');
        $this->realisasiPs($pr)
            ->assertStatus(422)->assertJsonPath('message', $this->pesanJalurLama($pr));

        $psDb = $this->psDariPr($pr);
        $this->assertSame('disetujui_finance', $psDb->status);
        $this->assertNull($psDb->total_aktual);
        $this->assertNull($psDb->id_supplier);
        $this->assertNull($psDb->tanggal_pembelian);
        $this->assertSame([null, null], array_map(fn ($i) => $i->harga_aktual, $this->itemsPs($psDb)));
        $this->assertSame($stokFilter, $this->stok($pr, 0));
        $this->assertSame($stokKampas, $this->stok($pr, 1));
        $this->assertSame(0, DB::table('sparepart_mutasi')->where('id_pembelian', $ps->id_pembelian)->count());
        $this->assertSame('diproses', $this->header($pr)->status);
        $this->assertSame([], $this->pengajuan($pr));

        $this->terbitkanPo($pr['id_permintaan'], $this->payloadPoSparepart($pr, $idSupplier, self::BIAYA_PO))
            ->assertStatus(200)->assertJsonPath('data.status', 'dipesan');

        $this->unggahNota($pr)->assertStatus(200);
        $this->realisasiPs($pr, ['id_supplier' => $idSupplier])
            ->assertStatus(422)->assertJsonPath('message', $this->pesanJalurLama($pr));
        $this->assertSame('disetujui_finance', $this->psDariPr($pr)->status);
        $this->assertSame($stokFilter, $this->stok($pr, 0));
        $this->assertSame('dipesan', $this->header($pr)->status);
        $this->assertSame($idSupplier, $this->header($pr)->id_supplier);
        $this->assertSame([], $this->pengajuan($pr));

        $res = $this->realisasiSparepart($pr)->assertStatus(200);
        $res->assertJsonPath('data.status', 'diterima')->assertJsonPath('data.id_supplier', $idSupplier);
        $this->assertAngka(40000, $res->json('data.diskon'));
        $this->assertAngka(11, $res->json('data.ppn_persen'));
        $this->assertAngka(38500, $res->json('data.ppn'));
        $this->assertAngka(15000, $res->json('data.ongkir'));
        $this->assertAngka(403500, $res->json('data.total_aktual'));

        $psDb = $this->psDariPr($pr);
        $this->assertSame('dibeli', $psDb->status);
        $this->assertSame($idSupplier, $psDb->id_supplier);
        $this->assertBiaya($psDb, 40000, 11, 38500, 15000, 403500);

        $header = $this->header($pr);
        $this->assertSame('diterima', $header->status);
        $this->assertSame('PO-202610-0001', $header->nomor_po);
        $this->assertSame($idSupplier, $header->id_supplier);
        $this->assertBiaya($header, 40000, 11, 38500, 15000, 403500);

        $pengajuan = $this->pengajuan($pr);
        $this->assertCount(1, $pengajuan);
        $this->assertSame($ps->id_pembelian, $pengajuan[0]->id_pembelian);
        $this->assertSame(403500.0, (float) $pengajuan[0]->nominal);
        $this->assertSame('Toko Onderdil', $pengajuan[0]->penerima);

        $this->assertSame($stokFilter + 2, $this->stok($pr, 0));
        $this->assertSame($stokKampas + 1, $this->stok($pr, 1));
        $this->assertSame(90000.0, (float) DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][0]['id_item'])->value('harga_aktual'));
    }

    public function test_pengaju_berperan_pengadaan_dengan_total_aktual_di_atas_batas_mandiri_tetap_wajib_po(): void
    {
        $pr = $this->prSparepartDisetujui('PENGADAAN');
        $this->assertTrue($pr['boleh_realisasi_mandiri']);
        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200)->assertJsonPath('data.status', 'diproses');
        $this->unggahNota($pr)->assertStatus(200);
        $stokFilter = $this->stok($pr, 0);
        $hargaMahal = [300000, 210000];

        $this->realisasiSparepart($pr, ['items' => $this->itemsRealisasi($pr, $hargaMahal)])
            ->assertStatus(422)->assertJsonPath('message', 'Total aktual Rp 810.000 melebihi batas mandiri Rp 500.000, terbitkan PO lebih dulu');
        $this->realisasiPs($pr, ['items' => $this->itemsRealisasiPs($pr, $hargaMahal)])
            ->assertStatus(422)->assertJsonPath('message', $this->pesanJalurLama($pr));

        $this->assertSame('disetujui_finance', $this->psDariPr($pr)->status);
        $this->assertNull($this->psDariPr($pr)->total_aktual);
        $this->assertSame($stokFilter, $this->stok($pr, 0));
        $this->assertSame('diproses', $this->header($pr)->status);
        $this->assertSame([], $this->pengajuan($pr));

        $this->realisasiSparepart($pr)->assertStatus(200)->assertJsonPath('data.status', 'diterima');
        $header = $this->header($pr);
        $this->assertSame('diterima', $header->status);
        $this->assertNull($header->nomor_po);
        $this->assertSame(390000.0, (float) $header->total_aktual);
        $this->assertSame($stokFilter + 2, $this->stok($pr, 0));
    }

    public function test_realisasi_sparepart_lewat_po_tanpa_nota_ditolak_tanpa_mengubah_stok_dan_status(): void
    {
        $pr = $this->prSparepartDiproses();
        $ps = $this->psDariPr($pr);
        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $stokFilter = $this->stok($pr, 0);
        $stokKampas = $this->stok($pr, 1);

        $this->actingAsRole('PENGADAAN');
        $this->terbitkanPo($pr['id_permintaan'], $this->payloadPoSparepart($pr, $this->makeSupplier('Toko Onderdil'), self::BIAYA_PO))
            ->assertStatus(200)->assertJsonPath('data.status', 'dipesan');
        $this->assertSame(0, $this->jumlahNota($pr));

        $this->realisasiSparepart($pr)
            ->assertStatus(422)->assertJsonPath('message', self::PESAN_TANPA_NOTA);

        $psDb = $this->psDariPr($pr);
        $this->assertSame('disetujui_finance', $psDb->status);
        $this->assertNull($psDb->total_aktual);
        $this->assertNull($psDb->id_supplier);
        $this->assertNull($psDb->tanggal_pembelian);
        $this->assertSame([null, null], array_map(fn ($i) => $i->harga_aktual, $this->itemsPs($psDb)));
        $this->assertSame($stokFilter, $this->stok($pr, 0));
        $this->assertSame($stokKampas, $this->stok($pr, 1));
        $this->assertSame(0, DB::table('sparepart_mutasi')->where('id_pembelian', $ps->id_pembelian)->count());
        $header = $this->header($pr);
        $this->assertSame('dipesan', $header->status);
        $this->assertNull($header->tanggal_pembelian);
        $this->assertSame(403500.0, (float) $header->total_aktual);
        $this->assertSame([], $this->pengajuan($pr));

        $this->unggahNota($pr)->assertStatus(200);
        $this->realisasiSparepart($pr)->assertStatus(200)->assertJsonPath('data.status', 'diterima');
        $this->assertSame('dibeli', $this->psDariPr($pr)->status);
        $this->assertSame($stokFilter + 2, $this->stok($pr, 0));
        $this->assertSame($stokKampas + 1, $this->stok($pr, 1));
    }

    public function test_total_pembelian_nol_ditolak_di_pesan_dan_dibeli_tanpa_memakan_nomor_po(): void
    {
        $pr = $this->prUmumDiproses();
        $idSupplier = $this->makeSupplier();
        $nolKarenaDiskon = $this->payloadUmum($pr, $idSupplier, ['diskon' => 870000, 'ppn_persen' => 11]);
        $nolKarenaHarga = $this->payloadUmum($pr, $idSupplier, [
            'items' => array_map(fn ($i) => ['id_item' => $i['id_item'], 'harga_aktual' => 0], $pr['items']),
        ]);

        foreach ([$nolKarenaDiskon, $nolKarenaHarga] as $payload) {
            $this->terbitkanPo($pr['id_permintaan'], $payload)
                ->assertStatus(422)->assertJsonPath('message', 'Total pembelian tidak boleh Rp 0');
        }

        $header = $this->header($pr);
        $this->assertSame('diproses', $header->status);
        $this->assertNull($header->nomor_po);
        $this->assertNull($header->id_supplier);
        $this->assertNull($header->total_aktual);
        $this->assertSame(0, DB::table('permintaan_pembelian_item')->where('id_permintaan', $pr['id_permintaan'])->whereNotNull('harga_aktual')->count());

        $po = $this->terbitkanPo($pr['id_permintaan'], array_merge($nolKarenaDiskon, ['ongkir' => 15000]))->assertStatus(200);
        $po->assertJsonPath('data.status', 'dipesan')->assertJsonPath('data.nomor_po', 'PO-202610-0001');
        $this->assertAngka(15000, $po->json('data.total_aktual'));

        $this->terbitkanPo($pr['id_permintaan'], $nolKarenaDiskon)
            ->assertStatus(422)->assertJsonPath('message', 'Total pembelian tidak boleh Rp 0');
        $this->tandaiDibeli($pr, $nolKarenaDiskon)
            ->assertStatus(422)->assertJsonPath('message', 'Total pembelian tidak boleh Rp 0');

        $header = $this->header($pr);
        $this->assertSame('dipesan', $header->status);
        $this->assertSame('PO-202610-0001', $header->nomor_po);
        $this->assertSame(15000.0, (float) $header->total_aktual);
        $this->assertSame([], $this->pengajuan($pr));
    }

    public function test_total_pembelian_nol_ditolak_di_realisasi_sparepart_mandiri(): void
    {
        $pr = $this->prSparepartDisetujui();
        $stokFilter = $this->stok($pr, 0);
        $this->actingAsPengaju($pr);
        $this->unggahNota($pr)->assertStatus(200);

        $this->realisasiSparepart($pr, ['items' => $this->itemsRealisasi($pr, [0, 0])])
            ->assertStatus(422)->assertJsonPath('message', 'Total pembelian tidak boleh Rp 0');
        $this->realisasiSparepart($pr, ['diskon' => 390000, 'ppn_persen' => 11])
            ->assertStatus(422)->assertJsonPath('message', 'Total pembelian tidak boleh Rp 0');

        $this->assertSame('disetujui', $this->header($pr)->status);
        $this->assertSame(0, DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
        $this->assertSame($stokFilter, $this->stok($pr, 0));
        $this->assertSame([], $this->pengajuan($pr));

        $res = $this->realisasiSparepart($pr, ['diskon' => 390000, 'ongkir' => 5000])->assertStatus(200);
        $res->assertJsonPath('data.status', 'diterima')->assertJsonPath('data.nomor_po', null);
        $this->assertAngka(5000, $res->json('data.total_aktual'));
        $this->assertBiaya($this->psDariPr($pr), 390000, 0, 0, 5000, 5000);
        $this->assertSame(5000.0, (float) $this->pengajuan($pr)[0]->nominal);
        $this->assertSame($stokFilter + 2, $this->stok($pr, 0));
    }

    public function test_total_pembelian_nol_ditolak_di_realisasi_sparepart_lewat_po(): void
    {
        $pr = $this->prSparepartDiproses();
        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $stokFilter = $this->stok($pr, 0);

        $this->actingAsRole('PENGADAAN');
        $this->terbitkanPo($pr['id_permintaan'], $this->payloadPoSparepart($pr, $this->makeSupplier('Toko Onderdil')))->assertStatus(200);
        $this->unggahNota($pr)->assertStatus(200);

        $this->realisasiSparepart($pr, ['items' => $this->itemsRealisasi($pr, [0, 0])])
            ->assertStatus(422)->assertJsonPath('message', 'Total pembelian tidak boleh Rp 0');

        $psDb = $this->psDariPr($pr);
        $this->assertSame('disetujui_finance', $psDb->status);
        $this->assertNull($psDb->total_aktual);
        $this->assertSame([null, null], array_map(fn ($i) => $i->harga_aktual, $this->itemsPs($psDb)));
        $this->assertSame($stokFilter, $this->stok($pr, 0));
        $this->assertSame('dipesan', $this->header($pr)->status);
        $this->assertSame(390000.0, (float) $this->header($pr)->total_aktual);
        $this->assertSame([], $this->pengajuan($pr));

        $this->realisasiSparepart($pr)->assertStatus(200)->assertJsonPath('data.status', 'diterima');
        $this->assertSame('dibeli', $this->psDariPr($pr)->status);
        $this->assertSame($stokFilter + 2, $this->stok($pr, 0));
    }

    public function test_nota_pr_umum_baru_bisa_diunggah_setelah_po_terbit(): void
    {
        $pr = $this->prUmumDisetujui();
        $this->actingAsPengaju($pr);
        $this->unggahNota($pr)->assertStatus(422)->assertJsonPath('message', 'Nota baru bisa diunggah setelah PO terbit');

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200)->assertJsonPath('data.status', 'diproses');
        $this->unggahNota($pr)->assertStatus(422)->assertJsonPath('message', 'Nota baru bisa diunggah setelah PO terbit');
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", [
            'tahap' => 'pengajuan', 'bukti' => [UploadedFile::fake()->image('penawaran.jpg')],
        ])->assertStatus(200);
        $this->assertSame(0, $this->jumlahNota($pr));

        $payload = $this->payloadUmum($pr, $this->makeSupplier());
        $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(200)->assertJsonPath('data.status', 'dipesan');
        $this->unggahNota($pr)->assertStatus(200)->assertJsonPath('data.status', 'dipesan');
        $this->assertSame(1, $this->jumlahNota($pr));

        $this->tandaiDibeli($pr, $payload)->assertStatus(200)->assertJsonPath('data.status', 'dibeli');
        $this->unggahNota($pr)->assertStatus(200);
        $this->assertSame(2, $this->jumlahNota($pr));
    }

    public function test_nota_pr_sparepart_di_atas_batas_hanya_setelah_po_sedangkan_mandiri_boleh_sejak_disetujui(): void
    {
        $mandiri = $this->prSparepartDisetujui();
        $this->assertTrue($mandiri['boleh_realisasi_mandiri']);
        $this->actingAsPengaju($mandiri);
        $this->unggahNota($mandiri)->assertStatus(200)->assertJsonPath('data.status', 'disetujui');
        $this->assertSame(1, $this->jumlahNota($mandiri));

        app(ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 100000);
        $besar = $this->prSparepartDisetujui();
        $this->assertFalse($besar['boleh_realisasi_mandiri']);

        $this->actingAsPengaju($besar);
        $this->unggahNota($besar)->assertStatus(422)->assertJsonPath('message', 'Nota baru bisa diunggah setelah PO terbit');

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$besar['id_permintaan']}/proses")->assertStatus(200)->assertJsonPath('data.status', 'diproses');
        $this->unggahNota($besar)->assertStatus(422)->assertJsonPath('message', 'Nota baru bisa diunggah setelah PO terbit');
        $this->assertSame(0, $this->jumlahNota($besar));

        $this->terbitkanPo($besar['id_permintaan'], $this->payloadPoSparepart($besar, $this->makeSupplier('Toko Onderdil')))
            ->assertStatus(200)->assertJsonPath('data.status', 'dipesan');
        $this->unggahNota($besar)->assertStatus(200)->assertJsonPath('data.status', 'dipesan');
        $this->assertSame(1, $this->jumlahNota($besar));

        $this->realisasiSparepart($besar)->assertStatus(200)->assertJsonPath('data.status', 'diterima');
        $this->unggahNota($besar)->assertStatus(422)->assertJsonPath('message', 'Nota pembelian hanya bisa diunggah sebelum realisasi');
        $this->assertSame(1, $this->jumlahNota($besar));
    }
}
