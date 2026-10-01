<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PermintaanPembelianPoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-28 09:00:00'));
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
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

    private function makeSupplier(string $nama = 'Toko ATK Jaya'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama,
            'alamat' => 'Jl. Merdeka No. 10, Jakarta', 'telepon' => '021-5550123', 'aktif' => 1, 'dibuat_pada' => now(),
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

    private function makeJenisKendaraan(): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => 'Truk Engkel', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePenggunaTenantLain(): \App\Models\Pengguna
    {
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        return \App\Models\Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
    }

    private function prosesDanUnggahNota(array $pr): void
    {
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
    }

    private function prUmumDiproses(?array $items = null): array
    {
        $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'ATK Oktober', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => $items ?? [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 55000],
                ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000],
            ],
        ])->assertStatus(201)->json('data');
        $this->prosesDanUnggahNota($pr);
        return $pr;
    }

    private function prAsetDiproses(): array
    {
        $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'Pengadaan 2 unit truk', 'tipe' => 'aset', 'alasan' => 'Ekspansi armada', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [[
                'jenis' => 'aset', 'id_jenis_kendaraan' => $this->makeJenisKendaraan(), 'merk' => 'Hino', 'model' => 'Dutro',
                'tahun' => 2026, 'qty' => 2, 'harga_estimasi' => 350000000,
            ]],
        ])->assertStatus(201)->json('data');
        $this->prosesDanUnggahNota($pr);
        return $pr;
    }

    private function dibeliUmum(array $pr, array $tambahan = []): TestResponse
    {
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", array_merge([
            'id_supplier'       => $this->makeSupplier(),
            'tanggal_pembelian' => '2026-09-28',
            'items'             => array_map(fn ($i) => [
                'id_item'      => $i['id_item'],
                'harga_aktual' => $i['jenis'] === 'barang' ? 52000 : 350000,
            ], $pr['items']),
        ], $tambahan));
    }

    private function dibeliAset(array $pr, array $termin, array $tambahan = []): TestResponse
    {
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", array_merge([
            'id_supplier'       => $this->makeSupplier('Dealer Hino Jaya'),
            'tanggal_pembelian' => '2026-09-28',
            'items'             => [['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 350000000]],
            'termin'            => $termin,
        ], $tambahan));
    }

    private function header(string $idPermintaan): object
    {
        return DB::table('permintaan_pembelian')->where('id_permintaan', $idPermintaan)->first();
    }

    private function panjangKolomMysql(string $kolom): int
    {
        config(['database.connections.mysql_ddl' => ['driver' => 'mysql', 'database' => 'ddl', 'prefix' => '']]);
        $koneksiAwal = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql_ddl');
        try {
            $migrasi = require database_path('migrations/2026_09_28_100000_tambah_po_ke_permintaan_pembelian.php');
            $sql = collect(DB::connection('mysql_ddl')->pretend(fn () => $migrasi->up()))->pluck('query')->implode(";\n");
        } finally {
            DB::setDefaultConnection($koneksiAwal);
            DB::purge('mysql_ddl');
        }

        $this->assertSame(1, preg_match('/`' . $kolom . '` varchar\((\d+)\)/', $sql, $cocok), $sql);
        return (int) $cocok[1];
    }

    private function tangkapTampilanPo(): \Closure
    {
        $tampilan = null;
        View::composer('exports.purchase-order', function ($view) use (&$tampilan) {
            $tampilan = $view;
        });
        return function () use (&$tampilan): string {
            $this->assertNotNull($tampilan, 'Template exports.purchase-order tidak dirender');
            return $tampilan->render();
        };
    }

    public function test_dibeli_dengan_diskon_ppn_ongkir_menerbitkan_po_dan_pengajuan_bernominal_grand_total(): void
    {
        $pr = $this->prUmumDiproses();
        $idBarang = collect($pr['items'])->firstWhere('jenis', 'barang')['id_barang'];

        $res = $this->dibeliUmum($pr, ['diskon' => 70000, 'ppn_persen' => 11, 'ongkir' => 25000])->assertStatus(200);

        $res->assertJsonPath('data.status', 'dibeli')->assertJsonPath('data.nomor_po', 'PO-202609-0001');
        $this->assertAngka(870000, $res->json('data.subtotal_aktual'));
        $this->assertAngka(70000, $res->json('data.diskon'));
        $this->assertAngka(11, $res->json('data.ppn_persen'));
        $this->assertAngka(88000, $res->json('data.ppn'));
        $this->assertAngka(25000, $res->json('data.ongkir'));
        $this->assertAngka(913000, $res->json('data.total_aktual'));
        $this->assertAngka(52000, collect($res->json('data.items'))->firstWhere('jenis', 'barang')['harga_aktual']);

        $header = $this->header($pr['id_permintaan']);
        $this->assertSame('PO-202609-0001', $header->nomor_po);
        $this->assertSame(70000.0, (float) $header->diskon);
        $this->assertSame(11.0, (float) $header->ppn_persen);
        $this->assertSame(88000.0, (float) $header->ppn);
        $this->assertSame(25000.0, (float) $header->ongkir);
        $this->assertSame(913000.0, (float) $header->total_aktual);
        $this->assertSame(52000.0, (float) DB::table('barang')->where('id_barang', $idBarang)->value('harga_standar'));

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->get();
        $this->assertCount(1, $pengajuan);
        $this->assertSame('pengadaan', $pengajuan[0]->kategori);
        $this->assertSame(913000.0, (float) $pengajuan[0]->nominal);

        $this->actingAsRole('KEUANGAN');
        $rincian = $this->getJson("/api/arus-kas/pengajuan/{$pengajuan[0]->id_pengajuan}/rincian-sumber")->assertStatus(200);
        $rincian->assertJsonPath('data.tipe', 'permintaan_pembelian')->assertJsonPath('data.data.nomor_po', 'PO-202609-0001');
        $this->assertAngka(870000, $rincian->json('data.data.subtotal_aktual'));
        $this->assertAngka(88000, $rincian->json('data.data.ppn'));
        $this->assertAngka(913000, $rincian->json('data.data.total_aktual'));
    }

    public function test_dibeli_tanpa_komponen_total_sama_subtotal_dan_po_tetap_terbit(): void
    {
        $pr = $this->prUmumDiproses();
        $res = $this->dibeliUmum($pr)->assertStatus(200);

        $res->assertJsonPath('data.nomor_po', 'PO-202609-0001');
        $this->assertAngka(870000, $res->json('data.subtotal_aktual'));
        $this->assertAngka(870000, $res->json('data.total_aktual'));
        $this->assertAngka(0, $res->json('data.diskon'));
        $this->assertAngka(0, $res->json('data.ppn_persen'));
        $this->assertAngka(0, $res->json('data.ppn'));
        $this->assertAngka(0, $res->json('data.ongkir'));
        $this->assertSame(870000.0, (float) DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->value('nominal'));

        $pr2 = $this->prUmumDiproses();
        $res2 = $this->dibeliUmum($pr2, ['diskon' => null, 'ppn_persen' => '', 'ongkir' => null])->assertStatus(200);
        $res2->assertJsonPath('data.nomor_po', 'PO-202609-0002');
        $this->assertAngka(870000, $res2->json('data.total_aktual'));
        $this->assertAngka(0, $res2->json('data.ppn'));
    }

    public function test_diskon_melebihi_subtotal_ditolak_tanpa_memakan_nomor_po(): void
    {
        $pr = $this->prUmumDiproses();

        $this->dibeliUmum($pr, ['diskon' => 870001, 'ppn_persen' => 11])
            ->assertStatus(422)->assertJsonPath('message', 'Diskon tidak boleh melebihi subtotal (Rp 870.000)');

        $header = $this->header($pr['id_permintaan']);
        $this->assertSame('diproses', $header->status);
        $this->assertNull($header->nomor_po);
        $this->assertNull($header->total_aktual);
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());

        $detail = $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->json('data');
        $this->assertArrayHasKey('nomor_po', $detail);
        $this->assertNull($detail['nomor_po']);
        $this->assertArrayHasKey('subtotal_aktual', $detail);
        $this->assertNull($detail['subtotal_aktual']);

        $res = $this->dibeliUmum($pr, ['diskon' => 870000, 'ppn_persen' => 11, 'ongkir' => 15000])->assertStatus(200);
        $res->assertJsonPath('data.nomor_po', 'PO-202609-0001');
        $this->assertAngka(0, $res->json('data.ppn'));
        $this->assertAngka(15000, $res->json('data.total_aktual'));
        $this->assertSame(15000.0, (float) DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->value('nominal'));
    }

    public function test_validasi_ppn_persen_maksimal_100_dan_komponen_tidak_boleh_negatif(): void
    {
        $pr = $this->prUmumDiproses();

        $this->dibeliUmum($pr, ['ppn_persen' => 100.01])->assertStatus(422)->assertJsonValidationErrors(['ppn_persen']);
        $this->dibeliUmum($pr, ['ppn_persen' => -1])->assertStatus(422)->assertJsonValidationErrors(['ppn_persen']);
        $this->dibeliUmum($pr, ['diskon' => -1])->assertStatus(422)->assertJsonValidationErrors(['diskon']);
        $this->dibeliUmum($pr, ['ongkir' => -0.01])->assertStatus(422)->assertJsonValidationErrors(['ongkir']);
        $this->dibeliUmum($pr, ['diskon' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['diskon']);

        $header = $this->header($pr['id_permintaan']);
        $this->assertSame('diproses', $header->status);
        $this->assertNull($header->nomor_po);

        $res = $this->dibeliUmum($pr, ['ppn_persen' => 100])->assertStatus(200);
        $res->assertJsonPath('data.nomor_po', 'PO-202609-0001');
        $this->assertAngka(870000, $res->json('data.ppn'));
        $this->assertAngka(1740000, $res->json('data.total_aktual'));
    }

    public function test_komponen_biaya_maksimal_dua_desimal_dan_dalam_batas_kolom(): void
    {
        $pr = $this->prUmumDiproses();

        $this->dibeliUmum($pr, ['ppn_persen' => 11.125])->assertStatus(422)->assertJsonValidationErrors(['ppn_persen']);
        $this->dibeliUmum($pr, ['diskon' => 1000.555])->assertStatus(422)->assertJsonValidationErrors(['diskon']);
        $this->dibeliUmum($pr, ['ongkir' => 10.001])->assertStatus(422)->assertJsonValidationErrors(['ongkir']);
        $this->dibeliUmum($pr, ['diskon' => 10000000000000])->assertStatus(422)->assertJsonValidationErrors(['diskon']);
        $this->dibeliUmum($pr, ['ongkir' => 10000000000000])->assertStatus(422)->assertJsonValidationErrors(['ongkir']);

        $header = $this->header($pr['id_permintaan']);
        $this->assertSame('diproses', $header->status);
        $this->assertNull($header->nomor_po);

        $res = $this->dibeliUmum($pr, ['ppn_persen' => 11.25, 'diskon' => 0.5, 'ongkir' => 1500.25])->assertStatus(200);
        $this->assertAngka(11.25, $res->json('data.ppn_persen'));
        $this->assertAngka(97875, $res->json('data.ppn'));
        $this->assertAngka(870000 - 0.5 + 97875 + 1500.25, $res->json('data.total_aktual'));
    }

    public function test_ppn_dibulatkan_ke_rupiah_penuh(): void
    {
        $prSetengah = $this->prUmumDiproses([['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 12000]]);
        $resSetengah = $this->patchJson("/api/permintaan-pembelian/{$prSetengah['id_permintaan']}/dibeli", [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => '2026-09-28',
            'items' => [['id_item' => $prSetengah['items'][0]['id_item'], 'harga_aktual' => 12345]],
            'ppn_persen' => 10,
        ])->assertStatus(200);
        $this->assertAngka(1235, $resSetengah->json('data.ppn'));
        $this->assertAngka(13580, $resSetengah->json('data.total_aktual'));
        $this->assertSame(1235.0, (float) $this->header($prSetengah['id_permintaan'])->ppn);

        $prBawah = $this->prUmumDiproses([['jenis' => 'jasa', 'nama_item' => 'Servis Genset', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 120000]]);
        $resBawah = $this->patchJson("/api/permintaan-pembelian/{$prBawah['id_permintaan']}/dibeli", [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => '2026-09-28',
            'items' => [['id_item' => $prBawah['items'][0]['id_item'], 'harga_aktual' => 123457]],
            'ppn_persen' => 11,
        ])->assertStatus(200);
        $this->assertAngka(13580, $resBawah->json('data.ppn'));
        $this->assertAngka(137037, $resBawah->json('data.total_aktual'));
        $this->assertSame(137037.0, (float) DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $prBawah['id_permintaan'])->value('nominal'));
    }

    public function test_termin_aset_dibandingkan_ke_grand_total(): void
    {
        $pr = $this->prAsetDiproses();
        $komponen = ['diskon' => 20000000, 'ppn_persen' => 11, 'ongkir' => 5000000];

        $this->dibeliAset($pr, [
            ['nama' => 'DP 30%', 'nominal' => 210000000, 'jatuh_tempo' => '2026-10-05'],
            ['nama' => 'Pelunasan', 'nominal' => 490000000],
        ], $komponen)->assertStatus(422)->assertJsonPath('message', 'Total termin harus sama dengan total aktual (Rp 759.800.000)');

        $header = $this->header($pr['id_permintaan']);
        $this->assertSame('diproses', $header->status);
        $this->assertNull($header->nomor_po);
        $this->assertSame(0, DB::table('permintaan_pembelian_termin')->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());

        $res = $this->dibeliAset($pr, [
            ['nama' => 'DP 30%', 'nominal' => 227940000, 'jatuh_tempo' => '2026-10-05'],
            ['nama' => 'Pelunasan', 'nominal' => 531860000],
        ], $komponen)->assertStatus(200);

        $res->assertJsonPath('data.status', 'dibeli')->assertJsonPath('data.nomor_po', 'PO-202609-0001');
        $this->assertAngka(700000000, $res->json('data.subtotal_aktual'));
        $this->assertAngka(74800000, $res->json('data.ppn'));
        $this->assertAngka(759800000, $res->json('data.total_aktual'));
        $this->assertCount(2, $res->json('data.termin'));

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->orderBy('nominal')->get();
        $this->assertCount(2, $pengajuan);
        $this->assertSame(['pembelian_aset', 'pembelian_aset'], $pengajuan->pluck('kategori')->all());
        $this->assertSame(227940000.0, (float) $pengajuan[0]->nominal);
        $this->assertSame(531860000.0, (float) $pengajuan[1]->nominal);
    }

    public function test_cetak_po_pdf_setelah_dibeli_ditolak_sebelum_dibeli_dan_404_untuk_perusahaan_lain(): void
    {
        $pr = $this->prUmumDiproses();
        $url = "/api/permintaan-pembelian/{$pr['id_permintaan']}/po/pdf";

        $this->get($url)->assertStatus(422)->assertJsonPath('message', 'PO belum tersedia untuk permintaan ini');

        $pembeli = $this->actingAsRole('PENGADAAN');
        $this->dibeliUmum($pr, ['tanggal_pembelian' => '2026-09-27', 'diskon' => 70000, 'ppn_persen' => 11, 'ongkir' => 25000])->assertStatus(200);

        $htmlPo = $this->tangkapTampilanPo();
        $res = $this->get($url);
        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('PO-202609-0001.pdf', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());

        $html = $htmlPo();
        foreach ([
            'PURCHASE ORDER', 'PO-202609-0001', '27/09/2026', $pr['nomor_permintaan'], $pr['username_pengaju'], $pembeli->username,
            'Toko ATK Jaya', 'Jl. Merdeka No. 10, Jakarta', '021-5550123', 'Kertas A4', 'Servis AC', 'ATK Oktober',
            'Rp 520.000', 'Rp 870.000', 'Rp 70.000', 'PPN 11%', 'Rp 88.000', 'Rp 25.000', 'Rp 913.000',
            'Sembilan Ratus Tiga Belas Ribu Rupiah',
        ] as $teks) {
            $this->assertStringContainsString($teks, $html);
        }

        $this->actingAsRole('KEUANGAN');
        $this->get($url)->assertStatus(200);

        \Laravel\Sanctum\Sanctum::actingAs($this->makePenggunaTenantLain(), ['*']);
        $this->get($url)->assertStatus(404);
    }

    public function test_cetak_po_pdf_pr_aset_memuat_jenis_kendaraan_dan_termin(): void
    {
        $pr = $this->prAsetDiproses();
        $this->dibeliAset($pr, [
            ['nama' => 'DP 30%', 'nominal' => 233100000, 'jatuh_tempo' => '2026-10-05'],
            ['nama' => 'Pelunasan', 'nominal' => 543900000],
        ], ['ppn_persen' => 11])->assertStatus(200);

        $htmlPo = $this->tangkapTampilanPo();
        $res = $this->get("/api/permintaan-pembelian/{$pr['id_permintaan']}/po/pdf");
        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));

        $html = $htmlPo();
        foreach ([
            'Dealer Hino Jaya', 'Hino Dutro 2026', 'Truk Engkel', 'Rp 350.000.000', 'Rp 700.000.000',
            'PPN 11%', 'Rp 77.000.000', 'Rp 777.000.000', 'DP 30%', '05/10/2026', 'Rp 233.100.000',
            'Pelunasan', 'Rp 543.900.000', 'Pengadaan 2 unit truk',
        ] as $teks) {
            $this->assertStringContainsString($teks, $html);
        }
        $this->assertStringNotContainsString('Diskon', $html);
        $this->assertStringNotContainsString('Ongkos Kirim', $html);
    }

    public function test_search_menemukan_pr_lewat_nomor_po(): void
    {
        $pr = $this->prUmumDiproses();
        $this->dibeliUmum($pr)->assertStatus(200);
        $this->prUmumDiproses();

        $this->actingAsRole('PENGADAAN');
        $this->getJson('/api/permintaan-pembelian?search=PO-202609-0001')->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_permintaan', $pr['id_permintaan'])
            ->assertJsonPath('data.0.nomor_po', 'PO-202609-0001');
        $this->getJson('/api/permintaan-pembelian?search=PO-2026')->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/permintaan-pembelian?search=PR-2026')->assertStatus(200)->assertJsonPath('meta.total', 2);
    }

    public function test_kolom_nomor_po_muat_nomor_terpanjang_yang_diizinkan_format_kode(): void
    {
        $prefix = 'SULITA-PO-JAKARTA-XY';
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/pengaturan-kode/purchase_order', ['prefix' => $prefix . 'Z', 'panjang_digit' => 8, 'reset' => 'bulanan'])
            ->assertStatus(422)->assertJsonValidationErrors(['prefix']);
        $this->putJson('/api/pengaturan-kode/purchase_order', ['prefix' => $prefix, 'panjang_digit' => 9, 'reset' => 'bulanan'])
            ->assertStatus(422)->assertJsonValidationErrors(['panjang_digit']);
        $this->putJson('/api/pengaturan-kode/purchase_order', ['prefix' => $prefix, 'panjang_digit' => 8, 'reset' => 'bulanan'])
            ->assertStatus(200);

        $pr = $this->prUmumDiproses();
        $nomorPo = "{$prefix}-202609-00000001";
        $this->dibeliUmum($pr)->assertStatus(200)->assertJsonPath('data.nomor_po', $nomorPo);
        $this->assertSame($nomorPo, $this->header($pr['id_permintaan'])->nomor_po);

        $this->assertGreaterThanOrEqual(strlen($nomorPo), $this->panjangKolomMysql('nomor_po'));
    }
}
