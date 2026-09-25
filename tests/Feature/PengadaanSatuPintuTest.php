<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PengadaanSatuPintuTest extends TestCase
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

    private function makeSupplier(string $nama = 'Toko Onderdil'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
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
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        return [$idLain, $pengguna];
    }

    private function payloadPrSparepart(array $override = []): array
    {
        return array_merge([
            'judul' => 'Stok spare part bulanan', 'tipe' => 'sparepart', 'alasan' => 'Stok menipis',
            'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart('Filter Oli', 100000), 'qty' => 2, 'harga_estimasi' => 100000],
                ['jenis' => 'sparepart', 'id_sparepart' => $this->makeSparepart('Kampas Rem', 200000), 'qty' => 1, 'harga_estimasi' => 200000],
            ],
        ], $override);
    }

    private function buatPrSparepart(string $peran = 'DISPATCHER', array $override = []): array
    {
        $this->actingAsRole($peran);
        return $this->postJson('/api/permintaan-pembelian', $this->payloadPrSparepart($override))->assertStatus(201)->json('data');
    }

    private function payloadPrUmum(array $override = []): array
    {
        return array_merge([
            'judul' => 'ATK Oktober', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 55000],
            ],
        ], $override);
    }

    private function buatPrUmum(string $peran = 'DISPATCHER', array $override = []): array
    {
        $this->actingAsRole($peran);
        return $this->postJson('/api/permintaan-pembelian', $this->payloadPrUmum($override))->assertStatus(201)->json('data');
    }

    private function prosesPr(string $idPermintaan): array
    {
        $this->actingAsRole('PENGADAAN');
        return $this->patchJson("/api/permintaan-pembelian/{$idPermintaan}/proses")->assertStatus(200)->json('data');
    }

    private function actingAsPengaju(array $pr): Pengguna
    {
        $pengguna = Pengguna::findOrFail($pr['id_pengaju']);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function unggahNotaPembelian(array $pr): void
    {
        $this->actingAsPengaju($pr);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", [
            'tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);
    }

    private function realisasiSparepart(array $pr, array $hargaPerItem, array $extra = [])
    {
        $items = [];
        foreach ($pr['items'] as $item) {
            $items[] = ['id_item' => $item['id_item'], 'harga_aktual' => $hargaPerItem[$item['id_item']]];
        }
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/realisasi-sparepart", array_merge([
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => $items,
        ], $extra));
    }

    public function test_pengaju_mandiri_merealisasi_dari_disetujui(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->assertSame('disetujui', $pr['status']);
        $this->assertTrue($pr['boleh_realisasi_mandiri']);
        $stokAwal = (int) DB::table('sparepart')->where('id_sparepart', $pr['items'][0]['id_sparepart'])->value('stok');

        $this->unggahNotaPembelian($pr);
        $idSupplier = $this->makeSupplier();
        $this->actingAsPengaju($pr);
        $res = $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 90000,
            $pr['items'][1]['id_item'] => 210000,
        ], ['id_supplier' => $idSupplier]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.id_supplier', $idSupplier)
            ->assertJsonPath('data.total_aktual', 390000);

        $prDb = DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
        $this->assertSame('diterima', $prDb->status);
        $this->assertSame($pr['id_pengaju'], $prDb->diproses_oleh);

        $ps = DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->assertNotNull($ps);
        $this->assertSame('dibeli', $ps->status);
        $this->assertSame(390000.0, (float) $ps->total_aktual);

        $this->assertSame($stokAwal + 2, (int) DB::table('sparepart')->where('id_sparepart', $pr['items'][0]['id_sparepart'])->value('stok'));

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->assertNotNull($pengajuan);
        $this->assertSame('pengadaan', $pengajuan->kategori);
        $this->assertSame($ps->id_pembelian, $pengajuan->id_pembelian);
    }

    public function test_batas_mandiri_membatasi_siapa_yang_bisa_merealisasi(): void
    {
        app(ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 100000);
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->assertFalse($pr['boleh_realisasi_mandiri']);

        $this->unggahNotaPembelian($pr);

        $this->actingAsPengaju($pr);
        $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 90000,
            $pr['items'][1]['id_item'] => 210000,
        ])->assertStatus(422)->assertJsonPath('message', 'PR di atas Rp 100.000 direalisasi oleh tim Pengadaan');
        $this->assertSame('disetujui', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));

        $this->actingAsRole('PENGADAAN');
        $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 90000,
            $pr['items'][1]['id_item'] => 210000,
        ])->assertStatus(200);
    }

    public function test_pengaju_mandiri_realisasi_dengan_total_aktual_di_atas_batas_ditolak(): void
    {
        app(ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 500000);
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->assertTrue($pr['boleh_realisasi_mandiri']);
        $this->unggahNotaPembelian($pr);

        $this->actingAsPengaju($pr);
        $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 300000,
            $pr['items'][1]['id_item'] => 210000,
        ])->assertStatus(422)->assertJsonPath('message', 'Total aktual Rp 810.000 melebihi batas mandiri Rp 500.000, realisasi harus oleh tim Pengadaan');

        $this->assertSame('disetujui', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
        $this->assertSame(0, DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
    }

    public function test_pengguna_lain_non_pengadaan_tidak_bisa_merealisasi(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->unggahNotaPembelian($pr);

        $this->actingAsRole('DISPATCHER');
        $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 90000,
            $pr['items'][1]['id_item'] => 210000,
        ])->assertStatus(422)->assertJsonPath('message', 'Hanya pengaju atau tim Pengadaan yang bisa mencatat realisasi PR ini');
    }

    public function test_realisasi_tanpa_nota_pembelian_ditolak(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->actingAsPengaju($pr);
        $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 90000,
            $pr['items'][1]['id_item'] => 210000,
        ])->assertStatus(422)->assertJsonPath('message', 'Unggah minimal 1 nota pembelian sebelum mencatat realisasi');
    }

    public function test_realisasi_sparepart_untuk_pr_umum_ditolak(): void
    {
        $pr = $this->buatPrUmum();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/realisasi-sparepart", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [['id_item' => (string) Str::uuid(), 'harga_aktual' => 1000]],
        ])->assertStatus(422)->assertJsonPath('message', 'Realisasi spare part hanya untuk PR tipe spare part');
    }

    public function test_realisasi_sparepart_tenant_lain_404(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER');
        [, $penggunaLain] = $this->makeTenantLain();
        Sanctum::actingAs($penggunaLain, ['*']);
        $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 90000,
            $pr['items'][1]['id_item'] => 210000,
        ])->assertStatus(404);
    }

    public function test_realisasi_dari_status_diproses_setelah_proses_manual(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER');
        $pr = $this->prosesPr($pr['id_permintaan']);
        $this->assertSame('diproses', $pr['status']);

        $this->unggahNotaPembelian($pr);

        $this->actingAsPengaju($pr);
        $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 90000,
            $pr['items'][1]['id_item'] => 210000,
        ])->assertStatus(200)->assertJsonPath('data.status', 'diterima');
    }

    public function test_unggah_bukti_tahap_pembelian_diizinkan_saat_disetujui_tahap_penerimaan_ditolak(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->assertSame('disetujui', $pr['status']);
        $this->actingAsPengaju($pr);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota1.jpg')]])
            ->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'penerimaan', 'bukti' => [UploadedFile::fake()->image('nota2.jpg')]])
            ->assertStatus(422)->assertJsonPath('message', 'PR spare part diterima otomatis saat realisasi');
    }

    public function test_transfer_pengajuan_setelah_realisasi_membuat_ps_lunas_dan_pr_selesai(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->unggahNotaPembelian($pr);
        $this->actingAsPengaju($pr);
        $this->realisasiSparepart($pr, [
            $pr['items'][0]['id_item'] => 90000,
            $pr['items'][1]['id_item'] => 210000,
        ])->assertStatus(200);

        $ps = DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $pengajuan->id_pengajuan)->update(['status' => 'siap_transfer']);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ])->assertStatus(200);

        $this->assertSame('lunas', DB::table('pembelian_sparepart')->where('id_pembelian', $ps->id_pembelian)->value('status'));
        $this->assertSame('selesai', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
    }

    public function test_notifikasi_approval_pr_mandiri_hanya_ke_pengaju(): void
    {
        $pengadaan = $this->actingAsRole('PENGADAAN');
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $pr['id_pengaju'],
            'judul'       => "PR {$pr['nomor_permintaan']} disetujui",
            'isi'         => 'Nilainya di bawah batas mandiri, silakan beli lalu catat realisasi',
        ]);
        $this->assertSame(0, DB::table('notifikasi')->where('id_pengguna', $pengadaan->id_pengguna)
            ->where('judul', "PR {$pr['nomor_permintaan']} siap diproses")->count());
    }

    public function test_notifikasi_approval_pr_di_atas_batas_ke_pengadaan(): void
    {
        app(ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 100000);
        $pengadaan = $this->actingAsRole('PENGADAAN');
        $pr = $this->buatPrSparepart('DISPATCHER');
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $pengadaan->id_pengguna,
            'judul'       => "PR {$pr['nomor_permintaan']} siap diproses",
        ]);
        $this->assertSame(0, DB::table('notifikasi')->where('id_pengguna', $pr['id_pengaju'])
            ->where('judul', "PR {$pr['nomor_permintaan']} disetujui")->count());
    }

    public function test_list_ps_sumber_langsung_tidak_memuat_ps_dari_pr(): void
    {
        $pr = $this->buatPrSparepart('DISPATCHER');
        $pr = $this->prosesPr($pr['id_permintaan']);
        $psDariPr = DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();

        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/pembelian-sparepart', [
            'id_supplier'       => $this->makeSupplier(),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [['id_sparepart' => $this->makeSparepart('Aki', 300000), 'qty' => 1, 'harga_estimasi' => 300000]],
            'bukti'             => [UploadedFile::fake()->image('nota-langsung.jpg')],
        ])->assertStatus(201);

        $res = $this->getJson('/api/pembelian-sparepart?sumber=langsung&limit=50')->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id_pembelian')->all();
        $this->assertNotContains($psDariPr->id_pembelian, $ids);

        $resPr = $this->getJson('/api/pembelian-sparepart?sumber=pr&limit=50')->assertStatus(200);
        $idsPr = collect($resPr->json('data'))->pluck('id_pembelian')->all();
        $this->assertContains($psDariPr->id_pembelian, $idsPr);
    }

    public function test_migrasi_memindahkan_menu_supplier_dan_menyalin_izin_ke_pr(): void
    {
        $idMenuSupplier = 'm0000001-0000-4000-8000-000000000085';
        $idMenuPembelian = 'm0000001-0000-4000-8000-000000000086';
        $idGrupPengadaan = 'm0000001-0000-4000-8000-000000000098';
        $idMenuPermintaan = 'm0000001-0000-4000-8000-000000000099';

        $this->assertSame($idGrupPengadaan, DB::table('menu')->where('id_menu', $idMenuSupplier)->value('id_menu_induk'));
        $this->assertSame(4, (int) DB::table('menu')->where('id_menu', $idMenuSupplier)->value('urutan'));

        DB::table('izin_peran')->where('id_menu', $idMenuPermintaan)
            ->where('kode_peran', 'DISPATCHER')->where('aksi', 'hapus')->whereNull('id_perusahaan')
            ->update(['diizinkan' => 0]);

        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'MAINTENANCE',
            'id_menu' => $idMenuPembelian, 'aksi' => 'ubah', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);

        (require database_path('migrations/2026_09_25_100006_gabung_pembelian_sparepart_ke_pengadaan.php'))->up();

        $this->assertSame(0, (int) DB::table('izin_peran')->where('id_menu', $idMenuPermintaan)
            ->where('kode_peran', 'DISPATCHER')->where('aksi', 'hapus')->whereNull('id_perusahaan')->value('diizinkan'));

        $this->assertSame(1, DB::table('izin_peran')->where('id_menu', $idMenuPermintaan)
            ->where('kode_peran', 'MAINTENANCE')->where('aksi', 'ubah')->whereNull('id_perusahaan')->where('diizinkan', 1)->count());
        $this->assertTrue(DB::table('menu_peran')->where('id_menu', $idMenuPermintaan)->where('kode_peran', 'MAINTENANCE')->exists());
        $this->assertTrue(DB::table('menu_peran')->where('id_menu', $idGrupPengadaan)->where('kode_peran', 'MAINTENANCE')->exists());
    }
}
