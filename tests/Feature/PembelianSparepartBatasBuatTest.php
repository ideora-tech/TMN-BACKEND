<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\PembelianSparepart\Events\PembelianSparepartDirealisasi;
use App\Modules\PembelianSparepart\PembelianSparepartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PembelianSparepartBatasBuatTest extends TestCase
{
    use RefreshDatabase;

    private const PESAN_BATAS_DEFAULT = 'Pembelian di atas Rp 500.000 wajib diajukan lewat Permintaan Pembelian (PR)';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeSupplier(string $nama = 'Toko Uji', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(float $hargaStandar = 50000, int $stok = 0): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'SP-' . Str::random(6), 'nama' => 'Sparepart Uji', 'satuan' => 'pcs',
            'harga_standar' => $hargaStandar, 'stok' => $stok, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payload(array $items, array $override = []): array
    {
        return array_merge([
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => $items,
            'bukti'             => [UploadedFile::fake()->image('nota.jpg')],
        ], $override);
    }

    private function postPs(array $items, array $override = []): TestResponse
    {
        return $this->postJson('/api/pembelian-sparepart', $this->payload($items, $override));
    }

    private function berikanIzinUbahPembelianSparepart(string $kodePeran): void
    {
        $idMenu = DB::table('menu')->where('path', '/pembelian-sparepart')->value('id_menu');
        DB::table('izin_peran')->insert([
            'id_izin'       => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'id_menu'       => $idMenu,
            'aksi'          => 'ubah',
            'diizinkan'     => 1,
            'dibuat_pada'   => now(),
        ]);
    }

    private function buatPrStub(float $totalEstimasi, int $jumlahItem = 2, int $jumlahBukti = 2): object
    {
        $idPermintaan = (string) Str::uuid();
        $nomor = 'PR-' . now()->format('Ym') . '-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $hargaPerItem = $totalEstimasi / $jumlahItem;
        DB::table('permintaan_pembelian')->insert([
            'id_permintaan'      => $idPermintaan,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nomor_permintaan'   => $nomor,
            'id_pengaju'         => (string) Str::uuid(),
            'tanggal_permintaan' => now()->toDateString(),
            'judul'              => 'Stok spare part bulanan',
            'alasan'             => 'Stok menipis',
            'status'             => 'diproses',
            'tipe'               => 'sparepart',
            'total_estimasi'     => $totalEstimasi,
            'dibuat_pada'        => now(),
        ]);
        $items = [];
        for ($i = 0; $i < $jumlahItem; $i++) {
            $items[] = (object) [
                'id_item'        => (string) Str::uuid(),
                'id_sparepart'   => $this->makeSparepart($hargaPerItem, 3),
                'nama_item'      => 'Sparepart Uji ' . ($i + 1),
                'qty'            => 1,
                'harga_estimasi' => $hargaPerItem,
            ];
        }
        $bukti = [];
        for ($i = 0; $i < $jumlahBukti; $i++) {
            $bukti[] = (object) ['url_file' => 'permintaan-pembelian/nota-' . $i . '.jpg', 'nama_asli' => 'nota-' . $i . '.jpg'];
        }
        return (object) [
            'id_permintaan'    => $idPermintaan,
            'id_perusahaan'    => self::PERUSAHAAN_ID,
            'nomor_permintaan' => $nomor,
            'judul'            => 'Stok spare part bulanan',
            'total_estimasi'   => $totalEstimasi,
            'items'            => $items,
            'bukti_mentah'     => $bukti,
        ];
    }

    private function realisasi(string $id, array $items, string $kodePeran, array $extra = []): TestResponse
    {
        $this->actingAsRole($kodePeran);
        return $this->patchJson("/api/pembelian-sparepart/{$id}/realisasi", array_merge([
            'tanggal_pembelian' => now()->toDateString(),
            'items' => array_map(fn ($i) => ['id_item' => $i['id_item'], 'harga_aktual' => $i['harga_estimasi']], $items),
        ], $extra));
    }

    public function test_ps_langsung_di_atas_batas_ditolak_dengan_arahan_pr(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $res = $this->postPs([['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 500_001]]);
        $res->assertStatus(422)->assertJsonPath('message', self::PESAN_BATAS_DEFAULT);
        $this->assertStringContainsString('Permintaan Pembelian', $res->json('message'));
        $this->assertSame(0, DB::table('pembelian_sparepart')->count());
    }

    public function test_ps_langsung_tepat_di_batas_diterima(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postPs([['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 500_000]])
            ->assertStatus(201)
            ->assertJsonPath('data.total_estimasi', 500000)
            ->assertJsonPath('data.id_permintaan_pembelian', null);
    }

    public function test_batas_dihitung_dari_total_qty_dikali_harga(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postPs([['id_sparepart' => $this->makeSparepart(), 'qty' => 2, 'harga_estimasi' => 300_000]])
            ->assertStatus(422)
            ->assertJsonPath('message', self::PESAN_BATAS_DEFAULT);
    }

    public function test_pesan_batas_mengikuti_setting_perusahaan(): void
    {
        app(ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 1_250_000);
        $this->actingAsRole('SUPERADMIN');
        $this->postPs([['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 1_250_000]])->assertStatus(201);
        $this->postPs([['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 1_250_001]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pembelian di atas Rp 1.250.000 wajib diajukan lewat Permintaan Pembelian (PR)');
    }

    public function test_update_ps_langsung_di_atas_batas_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSparepart = $this->makeSparepart();
        $id = $this->postPs([['id_sparepart' => $idSparepart, 'qty' => 1, 'harga_estimasi' => 200_000]])
            ->assertStatus(201)->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'diajukan']);

        $this->putJson("/api/pembelian-sparepart/{$id}", [
            'tanggal_pengajuan' => now()->toDateString(),
            'items' => [['id_sparepart' => $idSparepart, 'qty' => 1, 'harga_estimasi' => 600_000]],
        ])->assertStatus(422)->assertJsonPath('message', self::PESAN_BATAS_DEFAULT);

        $this->assertSame(200000.0, (float) DB::table('pembelian_sparepart')->where('id_pembelian', $id)->value('total_estimasi'));
    }

    public function test_realisasi_dengan_id_supplier_mengisi_nama_supplier(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $res = $this->postPs([['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 200_000]])->assertStatus(201);
        $id = $res->json('data.id_pembelian');
        $this->assertNull($res->json('data.id_supplier'));
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'disetujui_finance']);

        $idSupplier = $this->makeSupplier('Toko Realisasi');
        $this->realisasi($id, $res->json('data.items'), 'SUPERADMIN', ['id_supplier' => $idSupplier])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.id_supplier', $idSupplier)
            ->assertJsonPath('data.nama_supplier', 'Toko Realisasi');
    }

    public function test_realisasi_dengan_id_supplier_tenant_lain_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $res = $this->postPs([['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 200_000]])->assertStatus(201);
        $id = $res->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'disetujui_finance']);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $this->realisasi($id, $res->json('data.items'), 'SUPERADMIN', ['id_supplier' => $this->makeSupplier('Toko Lain', $idLain)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Supplier tidak ditemukan');
        $this->assertSame('disetujui_finance', DB::table('pembelian_sparepart')->where('id_pembelian', $id)->value('status'));
    }

    public function test_batas_mandiri_mengembalikan_setting_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->getJson('/api/pembelian-sparepart/batas-mandiri')
            ->assertStatus(200)
            ->assertJsonPath('data.batas', 500000);

        app(ArusKasService::class)->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 750_000);
        $this->getJson('/api/pembelian-sparepart/batas-mandiri')
            ->assertStatus(200)
            ->assertJsonPath('data.batas', 750000);
    }

    public function test_buat_dari_permintaan_melahirkan_ps_disetujui_finance_tanpa_pengajuan(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $pr = $this->buatPrStub(2_000_000);

        $ps = app(PembelianSparepartService::class)->buatDariPermintaan($pr, (string) $pengguna->id_pengguna);

        $this->assertSame('disetujui_finance', $ps->status);
        $this->assertSame($pr->id_permintaan, $ps->id_permintaan_pembelian);
        $this->assertSame($pr->nomor_permintaan, $ps->nomor_permintaan);
        $this->assertNull($ps->id_supplier);
        $this->assertNull($ps->id_perawatan);
        $this->assertSame(2000000.0, (float) $ps->total_estimasi);
        $this->assertSame("Dari {$pr->nomor_permintaan}: Stok spare part bulanan", $ps->keterangan);
        $this->assertSame((string) $pengguna->id_pengguna, $ps->disetujui_finance_oleh);
        $this->assertNotNull($ps->disetujui_finance_pada);
        $this->assertMatchesRegularExpression('/^PS-\d{6}-\d{4}$/', $ps->nomor_pengajuan);

        $this->assertCount(2, $ps->items);
        $idItemPr = array_map(fn ($i) => $i->id_item, $pr->items);
        foreach ($ps->items as $item) {
            $this->assertContains($item->id_item_permintaan, $idItemPr);
            $this->assertSame(1000000.0, (float) $item->harga_estimasi);
        }

        $this->assertCount(2, $ps->bukti);
        $buktiDb = DB::table('pembelian_sparepart_bukti')->where('id_pembelian', $ps->id_pembelian)->orderBy('nama_asli')->get();
        $this->assertSame('permintaan-pembelian/nota-0.jpg', $buktiDb[0]->url_file);
        $this->assertSame('nota-0.jpg', $buktiDb[0]->nama_asli);

        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->where('id_pembelian', $ps->id_pembelian)->count());

        $this->getJson("/api/pembelian-sparepart/{$ps->id_pembelian}")
            ->assertStatus(200)
            ->assertJsonPath('data.id_permintaan_pembelian', $pr->id_permintaan)
            ->assertJsonPath('data.nomor_permintaan', $pr->nomor_permintaan)
            ->assertJsonPath('data.wajib_pengadaan', true);
    }

    public function test_update_dan_delete_ps_dari_pr_ditolak(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $pr = $this->buatPrStub(200_000);
        $ps = app(PembelianSparepartService::class)->buatDariPermintaan($pr, (string) $pengguna->id_pengguna);
        $pesan = 'Pembelian yang lahir dari PR tidak bisa diubah atau dihapus, batalkan PR-nya';

        $this->putJson("/api/pembelian-sparepart/{$ps->id_pembelian}", [
            'tanggal_pengajuan' => now()->toDateString(),
            'items' => [['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 1000]],
        ])->assertStatus(422)->assertJsonPath('message', $pesan);

        $this->deleteJson("/api/pembelian-sparepart/{$ps->id_pembelian}")
            ->assertStatus(422)->assertJsonPath('message', $pesan);

        $this->assertNull(DB::table('pembelian_sparepart')->where('id_pembelian', $ps->id_pembelian)->value('dihapus_pada'));
    }

    public function test_realisasi_ps_dari_pr_hanya_pengadaan_dan_memicu_event(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $pr = $this->buatPrStub(200_000);
        $ps = app(PembelianSparepartService::class)->buatDariPermintaan($pr, (string) $pengguna->id_pengguna);
        $items = array_map(fn ($i) => ['id_item' => $i->id_item, 'harga_estimasi' => (float) $i->harga_estimasi], $ps->items);

        $this->realisasi($ps->id_pembelian, $items, 'DISPATCHER')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Realisasi pembelian dari PR hanya bisa dilakukan tim Pengadaan');

        Event::fake([PembelianSparepartDirealisasi::class]);
        $this->berikanIzinUbahPembelianSparepart('PENGADAAN');
        $idSupplier = $this->makeSupplier('Toko Pengadaan');
        $stokAwal = (int) DB::table('sparepart')->where('id_sparepart', $ps->items[0]->id_sparepart)->value('stok');

        $this->realisasi($ps->id_pembelian, $items, 'PENGADAAN', ['id_supplier' => $idSupplier])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.id_supplier', $idSupplier)
            ->assertJsonPath('data.nama_supplier', 'Toko Pengadaan')
            ->assertJsonPath('data.total_aktual', 200000);

        $this->assertSame($stokAwal + 1, (int) DB::table('sparepart')->where('id_sparepart', $ps->items[0]->id_sparepart)->value('stok'));
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->where('id_pembelian', $ps->id_pembelian)->count());

        Event::assertDispatched(PembelianSparepartDirealisasi::class, function (PembelianSparepartDirealisasi $e) use ($ps, $pr) {
            return $e->idPerusahaan === self::PERUSAHAAN_ID
                && $e->idPembelian === $ps->id_pembelian
                && $e->idPermintaanPembelian === $pr->id_permintaan
                && $e->idPengguna !== '';
        });
    }

    public function test_realisasi_ps_langsung_tidak_memicu_event(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $res = $this->postPs([['id_sparepart' => $this->makeSparepart(), 'qty' => 1, 'harga_estimasi' => 200_000]])->assertStatus(201);
        $id = $res->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'disetujui_finance']);

        Event::fake([PembelianSparepartDirealisasi::class]);
        $this->realisasi($id, $res->json('data.items'), 'SUPERADMIN')->assertStatus(200);
        Event::assertNotDispatched(PembelianSparepartDirealisasi::class);
    }

    public function test_batalkan_dari_permintaan_menolak_ps_dan_409_setelah_realisasi(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $service = app(PembelianSparepartService::class);

        $ps = $service->buatDariPermintaan($this->buatPrStub(200_000), (string) $pengguna->id_pengguna);
        $service->batalkanDariPermintaan($ps->id_pembelian, 'Anggaran ditunda', self::PERUSAHAAN_ID);
        $baris = DB::table('pembelian_sparepart')->where('id_pembelian', $ps->id_pembelian)->first();
        $this->assertSame('ditolak', $baris->status);
        $this->assertSame('PR dibatalkan: Anggaran ditunda', $baris->alasan_ditolak);

        $ps2 = $service->buatDariPermintaan($this->buatPrStub(200_000), (string) $pengguna->id_pengguna);
        Event::fake([PembelianSparepartDirealisasi::class]);
        $items = array_map(fn ($i) => ['id_item' => $i->id_item, 'harga_estimasi' => (float) $i->harga_estimasi], $ps2->items);
        $this->realisasi($ps2->id_pembelian, $items, 'SUPERADMIN')->assertStatus(200);

        try {
            $service->batalkanDariPermintaan($ps2->id_pembelian, 'Terlambat', self::PERUSAHAAN_ID);
            $this->fail('Seharusnya 409');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('dibeli', DB::table('pembelian_sparepart')->where('id_pembelian', $ps2->id_pembelian)->value('status'));
    }

    public function test_ps_dari_pr_tidak_terlihat_tenant_lain(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $ps = app(PembelianSparepartService::class)->buatDariPermintaan($this->buatPrStub(200_000), (string) $pengguna->id_pengguna);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penggunaLain = \App\Models\Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($penggunaLain, ['*']);

        $this->getJson("/api/pembelian-sparepart/{$ps->id_pembelian}")->assertStatus(404);
        $this->assertSame(0, count($this->getJson('/api/pembelian-sparepart?limit=50')->json('data')));
    }
}
