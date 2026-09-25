<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermintaanPembelianAsetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeJenisKendaraan(string $nama = 'Truk Engkel', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
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

    private function itemAset(array $override = []): array
    {
        return array_merge([
            'jenis' => 'aset', 'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'merk' => 'Hino', 'model' => 'Dutro', 'tahun' => 2026, 'qty' => 2, 'harga_estimasi' => 350000000,
        ], $override);
    }

    private function payloadPrAset(array $override = []): array
    {
        return array_merge([
            'judul' => 'Pengadaan 2 unit truk', 'tipe' => 'aset', 'alasan' => 'Ekspansi armada',
            'tanggal_permintaan' => now()->toDateString(),
            'items' => [$this->itemAset()],
        ], $override);
    }

    private function buatPrAset(string $peran = 'DISPATCHER', array $override = []): array
    {
        $this->actingAsRole($peran);
        return $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset($override))->assertStatus(201)->json('data');
    }

    private function buatEventType(string $kode, string $idApprover, int $aktif = 1): string
    {
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => $kode, 'nama' => 'Approval ' . $kode, 'mode_resolusi' => 'pinned', 'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType, 'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
        ]);
        return $idEventType;
    }

    private function kodeEventPengajuanAktif(string $idPermintaan): array
    {
        return DB::table('approval_pengajuan as ap')
            ->join('approval_event_type as et', 'et.id_event_type', '=', 'ap.id_event_type')
            ->where('ap.id_referensi', $idPermintaan)
            ->whereNull('ap.dihapus_pada')
            ->orderBy('ap.dibuat_pada')
            ->get(['et.kode', 'ap.status'])
            ->map(fn ($r) => [$r->kode, $r->status])
            ->all();
    }

    public function test_validasi_item_aset(): void
    {
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [$this->itemAset(['id_jenis_kendaraan' => null])]]))
            ->assertStatus(422)->assertJsonPath('message', 'Jenis kendaraan unit wajib dipilih');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [$this->itemAset(['merk' => null])]]))
            ->assertStatus(422)->assertJsonPath('message', 'Merk unit wajib diisi');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [$this->itemAset(['merk' => '   '])]]))
            ->assertStatus(422)->assertJsonPath('message', 'Merk unit wajib diisi');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [$this->itemAset(['tahun' => null])]]))
            ->assertStatus(422)->assertJsonPath('message', 'Tahun unit wajib diisi antara 1990 sampai 2100');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [$this->itemAset(['tahun' => 1980])]]))
            ->assertStatus(422);
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [$this->itemAset(['tahun' => 2101])]]))
            ->assertStatus(422);

        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [
            $this->itemAset(),
            ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas', 'qty' => 1, 'satuan' => 'rim', 'harga_estimasi' => 1000],
        ]]))->assertStatus(422)->assertJsonPath('message', 'PR tipe aset tidak boleh dicampur jenis item lain');

        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['tipe' => 'umum']))
            ->assertStatus(422)->assertJsonPath('message', 'Item aset hanya untuk PR tipe aset');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['tipe' => 'sparepart']))
            ->assertStatus(422)->assertJsonPath('message', 'PR tipe spare part tidak boleh dicampur barang atau jasa');

        [$idLain] = $this->makeTenantLain();
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [$this->itemAset(['id_jenis_kendaraan' => $this->makeJenisKendaraan('Asing', $idLain)])]]))
            ->assertStatus(422)->assertJsonPath('message', 'Jenis kendaraan tidak ditemukan');
        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['items' => [$this->itemAset(['id_jenis_kendaraan' => (string) Str::uuid()])]]))
            ->assertStatus(422)->assertJsonPath('message', 'Jenis kendaraan tidak ditemukan');

        $this->postJson('/api/permintaan-pembelian', $this->payloadPrAset(['tipe' => 'lainnya']))->assertStatus(422);

        $this->assertSame(0, DB::table('permintaan_pembelian')->count());
    }

    public function test_buat_pr_aset_menyusun_nama_item_dan_satuan_otomatis(): void
    {
        $idJenis = $this->makeJenisKendaraan('Truk Engkel');
        $pr = $this->buatPrAset('DISPATCHER', ['items' => [
            $this->itemAset(['id_jenis_kendaraan' => $idJenis]),
            $this->itemAset(['merk' => 'Isuzu', 'model' => null, 'tahun' => 2025, 'qty' => 1, 'harga_estimasi' => 400000000, 'nama_item' => 'diabaikan', 'satuan' => 'pcs']),
        ]]);

        $this->assertSame('aset', $pr['tipe']);
        $this->assertSame('disetujui', $pr['status']);
        $this->assertSame(1100000000.0, (float) $pr['total_estimasi']);
        $this->assertCount(2, $pr['items']);

        $item = $pr['items'][0];
        $this->assertSame('aset', $item['jenis']);
        $this->assertSame('Hino Dutro 2026', $item['nama_item']);
        $this->assertSame('unit', $item['satuan']);
        $this->assertSame(2, $item['qty']);
        $this->assertSame(350000000.0, (float) $item['harga_estimasi']);
        $this->assertSame(700000000.0, (float) $item['subtotal_estimasi']);
        $this->assertSame($idJenis, $item['id_jenis_kendaraan']);
        $this->assertSame('Truk Engkel', $item['nama_jenis_kendaraan']);
        $this->assertSame('Hino', $item['merk']);
        $this->assertSame('Dutro', $item['model']);
        $this->assertSame(2026, $item['tahun']);
        $this->assertSame([], $item['armada_terdaftar']);
        $this->assertNull($item['id_barang']);
        $this->assertNull($item['id_sparepart']);
        $this->assertNull($item['qty_diterima']);

        $this->assertSame('Isuzu 2025', $pr['items'][1]['nama_item']);
        $this->assertNull($pr['items'][1]['model']);
        $this->assertSame('unit', $pr['items'][1]['satuan']);

        $this->assertSame([], $pr['termin']);
        $this->assertFalse($pr['termin_lunas']);

        $db = DB::table('permintaan_pembelian_item')->where('id_item', $item['id_item'])->first();
        $this->assertSame($idJenis, $db->id_jenis_kendaraan);
        $this->assertSame('Hino', $db->merk);
        $this->assertSame('Dutro', $db->model);
        $this->assertSame(2026, (int) $db->tahun);

        $this->actingAsRole('PENGADAAN');
        $this->getJson('/api/permintaan-pembelian?tipe=aset')->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/permintaan-pembelian?tipe=umum')->assertStatus(200)->assertJsonPath('meta.total', 0);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.tipe', 'aset')
            ->assertJsonPath('data.items.0.nama_jenis_kendaraan', 'Truk Engkel');
    }

    public function test_update_pr_aset_memvalidasi_ulang_item_aset(): void
    {
        $pr = $this->buatPrAset();
        $this->actingAsRole('SUPERADMIN');
        $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", $this->payloadPrAset(['tipe' => 'umum']))
            ->assertStatus(422)->assertJsonPath('message', 'Item aset hanya untuk PR tipe aset');

        $hasil = $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", $this->payloadPrAset(['items' => [
            $this->itemAset(['merk' => 'Mitsubishi', 'model' => 'Canter', 'tahun' => 2027, 'qty' => 3, 'harga_estimasi' => 300000000]),
        ]]))->assertStatus(200)->json('data');
        $this->assertSame('aset', $hasil['tipe']);
        $this->assertSame('Mitsubishi Canter 2027', $hasil['items'][0]['nama_item']);
        $this->assertSame(900000000.0, (float) $hasil['total_estimasi']);
        $this->assertCount(1, $hasil['items']);
    }

    public function test_approval_aset_memakai_kode_aset_bila_aktif(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventType('permintaan_pembelian_aset', $approver->id_pengguna);
        $this->buatEventType('permintaan_pembelian', $approver->id_pengguna);

        $pr = $this->buatPrAset('DISPATCHER');
        $this->assertSame('menunggu_approval', $pr['status']);
        $this->assertSame([['permintaan_pembelian_aset', 'menunggu']], $this->kodeEventPengajuanAktif($pr['id_permintaan']));

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $this->getJson('/api/approval-pengajuan/menunggu-saya')->assertStatus(200)
            ->assertJsonPath('data.0.kode_event_type', 'permintaan_pembelian_aset')
            ->assertJsonPath('data.0.nomor_referensi', $pr['nomor_permintaan'])
            ->assertJsonPath('data.0.keterangan_referensi', 'Pengadaan 2 unit truk');

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi('permintaan_pembelian_aset', $pr['id_permintaan'], $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
        $this->assertSame('disetujui', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $pr['id_pengaju'], 'judul' => "PR {$pr['nomor_permintaan']} disetujui"]);
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $pr['id_pengaju'], 'tipe' => 'approval_keputusan',
            'link' => '/permintaan-pembelian?detail=' . $pr['id_permintaan'],
        ]);

        $this->actingAsRole('DISPATCHER');
        $prUmum = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'ATK', 'tipe' => 'umum', 'alasan' => 'Rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000]],
        ])->assertStatus(201)->json('data');
        $this->assertSame('menunggu_approval', $prUmum['status']);
        $this->assertSame([['permintaan_pembelian', 'menunggu']], $this->kodeEventPengajuanAktif($prUmum['id_permintaan']));
    }

    public function test_approval_aset_jatuh_ke_kode_pr_umum_bila_kode_aset_tidak_aktif(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventType('permintaan_pembelian', $approver->id_pengguna);

        $pr = $this->buatPrAset('DISPATCHER');
        $this->assertSame('menunggu_approval', $pr['status']);
        $this->assertSame([['permintaan_pembelian', 'menunggu']], $this->kodeEventPengajuanAktif($pr['id_permintaan']));

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi('permintaan_pembelian', $pr['id_permintaan'], $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
        $this->assertSame('disetujui', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));

        $this->buatEventType('permintaan_pembelian_aset', $approver->id_pengguna, 0);
        $pr2 = $this->buatPrAset('DISPATCHER');
        $this->assertSame([['permintaan_pembelian', 'menunggu']], $this->kodeEventPengajuanAktif($pr2['id_permintaan']));
    }

    public function test_approval_aset_langsung_disetujui_bila_kedua_kode_nonaktif(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventType('permintaan_pembelian', $approver->id_pengguna, 0);
        $this->buatEventType('permintaan_pembelian_aset', $approver->id_pengguna, 0);

        $pr = $this->buatPrAset('DISPATCHER');
        $this->assertSame('disetujui', $pr['status']);
        $this->assertSame([], $this->kodeEventPengajuanAktif($pr['id_permintaan']));
    }

    public function test_ditolak_lalu_edit_mengajukan_ulang_dan_batal_membatalkan_kode_aset(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventType('permintaan_pembelian_aset', $approver->id_pengguna);
        $pr = $this->buatPrAset('DISPATCHER');

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi('permintaan_pembelian_aset', $pr['id_permintaan'], $approver->id_pengguna, 'tolak', 'Anggaran belum ada', self::PERUSAHAAN_ID);
        $this->assertSame('ditolak', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::findOrFail($pr['id_pengaju']), ['*']);
        $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", $this->payloadPrAset(['judul' => 'Revisi']))
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval')->assertJsonPath('data.alasan_ditolak', null);
        $this->assertSame([
            ['permintaan_pembelian_aset', 'ditolak'],
            ['permintaan_pembelian_aset', 'menunggu'],
        ], $this->kodeEventPengajuanAktif($pr['id_permintaan']));

        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/batal", ['alasan' => 'Batal'])->assertStatus(200);
        $this->assertSame([
            ['permintaan_pembelian_aset', 'ditolak'],
            ['permintaan_pembelian_aset', 'dibatalkan'],
        ], $this->kodeEventPengajuanAktif($pr['id_permintaan']));
    }

    public function test_hapus_pr_aset_menunggu_membatalkan_approval_kode_aset(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventType('permintaan_pembelian_aset', $approver->id_pengguna);
        $pr = $this->buatPrAset('DISPATCHER');
        $this->deleteJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200);
        $this->assertSame([['permintaan_pembelian_aset', 'dibatalkan']], $this->kodeEventPengajuanAktif($pr['id_permintaan']));
        $this->assertNotNull(DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('dihapus_pada'));
    }

    public function test_pr_aset_tenant_lain_tidak_bisa_diakses(): void
    {
        $pr = $this->buatPrAset();
        [, $penggunaLain] = $this->makeTenantLain();
        \Laravel\Sanctum\Sanctum::actingAs($penggunaLain, ['*']);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(404);
        $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", $this->payloadPrAset())->assertStatus(404);
        $this->assertCount(0, $this->getJson('/api/permintaan-pembelian?limit=50')->assertStatus(200)->json('data'));
    }

    private function makeSupplier(string $nama = 'Dealer Hino Jaya'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert(['id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now()]);
        return $id;
    }

    private function prAsetSampaiDiproses(array $override = []): array
    {
        $pr = $this->buatPrAset('DISPATCHER', $override);
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('po.jpg')]])->assertStatus(200);
        return $pr;
    }

    private function payloadDibeli(array $pr, array $override = []): array
    {
        return array_merge([
            'id_supplier'       => $this->makeSupplier(),
            'tanggal_pembelian' => '2026-09-30',
            'items'             => [['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 350000000]],
            'termin'            => [
                ['nama' => 'DP 30%', 'nominal' => 210000000, 'jatuh_tempo' => '2026-10-05'],
                ['nama' => 'Pelunasan', 'nominal' => 490000000, 'jatuh_tempo' => null],
            ],
        ], $override);
    }

    private function prAsetSampaiDibeli(): array
    {
        $pr = $this->prAsetSampaiDiproses();
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $this->payloadDibeli($pr))->assertStatus(200)->json('data');
    }

    private function transferPengajuan(string $idPengajuan, string $tanggal = '2026-10-05')
    {
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['status' => 'siap_transfer']);
        $this->actingAsRole('KEUANGAN');
        return $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => $tanggal, 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ]);
    }

    public function test_dibeli_aset_wajib_termin_dan_total_termin_harus_sama(): void
    {
        $pr = $this->prAsetSampaiDiproses();
        $url = "/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli";

        $this->patchJson($url, $this->payloadDibeli($pr, ['termin' => null]))
            ->assertStatus(422)->assertJsonPath('message', 'Daftar termin pembayaran wajib diisi untuk PR aset');
        $this->patchJson($url, $this->payloadDibeli($pr, ['termin' => []]))
            ->assertStatus(422)->assertJsonPath('message', 'Daftar termin pembayaran wajib diisi untuk PR aset');
        $this->patchJson($url, $this->payloadDibeli($pr, ['termin' => [['nama' => 'DP', 'nominal' => 0]]]))->assertStatus(422);
        $this->patchJson($url, $this->payloadDibeli($pr, ['termin' => [['nominal' => 700000000]]]))->assertStatus(422);
        $this->patchJson($url, $this->payloadDibeli($pr, ['termin' => [
            ['nama' => 'DP 30%', 'nominal' => 210000000, 'jatuh_tempo' => '2026-10-05'],
            ['nama' => 'Pelunasan', 'nominal' => 400000000],
        ]]))->assertStatus(422)->assertJsonPath('message', 'Total termin harus sama dengan total aktual (Rp 700.000.000)');

        $this->assertSame('diproses', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
        $this->assertSame(0, DB::table('permintaan_pembelian_termin')->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_dibeli_aset_membuat_termin_dan_pengajuan_pembelian_aset_per_termin(): void
    {
        $pr = $this->prAsetSampaiDiproses();
        $idSupplier = $this->makeSupplier('Dealer Hino Jaya');
        $hasil = $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $this->payloadDibeli($pr, ['id_supplier' => $idSupplier]))
            ->assertStatus(200)->json('data');

        $this->assertSame('dibeli', $hasil['status']);
        $this->assertSame(700000000.0, (float) $hasil['total_aktual']);
        $this->assertSame('2026-09-30', $hasil['tanggal_pembelian']);
        $this->assertSame(350000000.0, (float) $hasil['items'][0]['harga_aktual']);
        $this->assertNull($hasil['pengajuan_keuangan']);
        $this->assertFalse($hasil['termin_lunas']);
        $this->assertCount(2, $hasil['termin']);

        $t1 = $hasil['termin'][0];
        $this->assertSame(1, $t1['urutan']);
        $this->assertSame('DP 30%', $t1['nama']);
        $this->assertSame(210000000.0, (float) $t1['nominal']);
        $this->assertSame('2026-10-05', $t1['jatuh_tempo']);
        $this->assertSame('menunggu', $t1['status']);
        $this->assertNull($t1['tanggal_transfer']);
        $this->assertNotNull($t1['pengajuan']);
        $this->assertSame('pembelian_aset', $t1['pengajuan']['kategori']);
        $this->assertSame(210000000.0, (float) $t1['pengajuan']['nominal']);
        $this->assertSame('disetujui', $t1['pengajuan']['status']);

        $t2 = $hasil['termin'][1];
        $this->assertSame(2, $t2['urutan']);
        $this->assertSame('Pelunasan', $t2['nama']);
        $this->assertNull($t2['jatuh_tempo']);
        $this->assertSame(490000000.0, (float) $t2['pengajuan']['nominal']);

        $terminDb = DB::table('permintaan_pembelian_termin')->where('id_permintaan', $pr['id_permintaan'])->orderBy('urutan')->get();
        $this->assertCount(2, $terminDb);
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->orderBy('nominal')->get();
        $this->assertCount(2, $pengajuan);
        foreach ($pengajuan as $p) {
            $this->assertSame('pembelian_aset', $p->kategori);
            $this->assertSame('Dealer Hino Jaya', $p->penerima);
            $this->assertNotNull($p->id_termin_pembelian);
        }
        $this->assertSame($terminDb[0]->id_pengajuan, $pengajuan[0]->id_pengajuan);
        $this->assertSame($terminDb[0]->id_termin, $pengajuan[0]->id_termin_pembelian);
        $this->assertSame($terminDb[1]->id_pengajuan, $pengajuan[1]->id_pengajuan);
        $this->assertSame("{$pr['nomor_permintaan']} · Termin 1/2: DP 30%", $pengajuan[0]->keterangan);
        $this->assertSame("{$pr['nomor_permintaan']} · Termin 2/2: Pelunasan", $pengajuan[1]->keterangan);
        $this->assertSame($pengajuan[0]->nomor_pengajuan, $t1['pengajuan']['nomor_pengajuan']);

        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $pr['id_pengaju'], 'judul' => "PR {$pr['nomor_permintaan']} sudah dibeli, 2 termin pembayaran dibuat"]);

        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.termin.0.pengajuan.nomor_pengajuan', $pengajuan[0]->nomor_pengajuan)
            ->assertJsonPath('data.termin.1.pengajuan.nomor_pengajuan', $pengajuan[1]->nomor_pengajuan);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/pengajuan")->assertStatus(200)->assertJsonPath('data', null);

        $this->actingAsRole('KEUANGAN');
        $this->getJson("/api/arus-kas/pengajuan/{$pengajuan[0]->id_pengajuan}/rincian-sumber")->assertStatus(200)
            ->assertJsonPath('data.tipe', 'permintaan_pembelian')
            ->assertJsonPath('data.data.nomor_permintaan', $pr['nomor_permintaan']);
    }

    public function test_dibeli_pr_umum_mengabaikan_termin(): void
    {
        $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'Servis', 'tipe' => 'umum', 'alasan' => 'Rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000]],
        ])->assertStatus(201)->json('data');
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        $hasil = $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 350000]],
            'termin' => [['nama' => 'DP', 'nominal' => 1000]],
        ])->assertStatus(200)->json('data');
        $this->assertSame([], $hasil['termin']);
        $this->assertSame(0, DB::table('permintaan_pembelian_termin')->count());
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->get();
        $this->assertCount(1, $pengajuan);
        $this->assertSame('pengadaan', $pengajuan[0]->kategori);
        $this->assertNull($pengajuan[0]->id_termin_pembelian);
        $this->assertNotNull($hasil['pengajuan_keuangan']);

        $this->transferPengajuan($pengajuan[0]->id_pengajuan)->assertStatus(409);
        $this->assertSame('dibeli', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
    }

    public function test_transfer_termin_boleh_saat_pr_dibeli_dan_selesai_hanya_setelah_diterima_dan_lunas(): void
    {
        $pr = $this->prAsetSampaiDibeli();
        $termin = DB::table('permintaan_pembelian_termin')->where('id_permintaan', $pr['id_permintaan'])->orderBy('urutan')->get();

        $this->transferPengajuan($termin[0]->id_pengajuan, '2026-10-05')->assertStatus(200)
            ->assertJsonPath('data.status', 'ditransfer');
        $t1 = DB::table('permintaan_pembelian_termin')->where('id_termin', $termin[0]->id_termin)->first();
        $this->assertSame('ditransfer', $t1->status);
        $this->assertSame('2026-10-05', $t1->tanggal_transfer);
        $this->assertSame('menunggu', DB::table('permintaan_pembelian_termin')->where('id_termin', $termin[1]->id_termin)->value('status'));
        $header = DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
        $this->assertSame('dibeli', $header->status);
        $this->assertNull($header->tanggal_pembayaran);

        $this->actingAsRole('PENGADAAN');
        $detail = $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->json('data');
        $this->assertFalse($detail['termin_lunas']);
        $this->assertSame('ditransfer', $detail['termin'][0]['status']);
        $this->assertSame('2026-10-05', $detail['termin'][0]['tanggal_transfer']);
        $this->assertSame('ditransfer', $detail['termin'][0]['pengajuan']['status']);
        $this->assertSame('2026-10-05', $detail['termin'][0]['pengajuan']['tanggal_transfer']);

        DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->update(['status' => 'diterima']);
        $this->transferPengajuan($termin[1]->id_pengajuan, '2026-11-01')->assertStatus(200);
        $header = DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
        $this->assertSame('selesai', $header->status);
        $this->assertSame('2026-11-01', $header->tanggal_pembayaran);

        $this->actingAsRole('PENGADAAN');
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.termin_lunas', true)
            ->assertJsonPath('data.termin.1.status', 'ditransfer');
    }

    public function test_semua_termin_ditransfer_saat_pr_masih_dibeli_tidak_menyelesaikan_pr(): void
    {
        $pr = $this->prAsetSampaiDibeli();
        $termin = DB::table('permintaan_pembelian_termin')->where('id_permintaan', $pr['id_permintaan'])->orderBy('urutan')->get();
        $this->transferPengajuan($termin[0]->id_pengajuan, '2026-10-05')->assertStatus(200);
        $this->transferPengajuan($termin[1]->id_pengajuan, '2026-11-01')->assertStatus(200);

        $header = DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
        $this->assertSame('dibeli', $header->status);
        $this->assertNull($header->tanggal_pembayaran);
        $this->assertSame(0, DB::table('permintaan_pembelian_termin')->where('id_permintaan', $pr['id_permintaan'])->where('status', 'menunggu')->count());

        $this->actingAsRole('PENGADAAN');
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.termin_lunas', true);
    }

    public function test_hapus_pengajuan_termin_pr_aset_ditolak_dan_termin_tetap_tertaut(): void
    {
        $pr = $this->prAsetSampaiDibeli();
        $termin = DB::table('permintaan_pembelian_termin')->where('id_permintaan', $pr['id_permintaan'])->orderBy('urutan')->get();
        $idPengajuan = $termin[0]->id_pengajuan;

        $this->actingAsRole('KEUANGAN');
        foreach (['menunggu_approval', 'ditolak'] as $status) {
            DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['status' => $status]);
            $this->deleteJson("/api/arus-kas/pengajuan/{$idPengajuan}")->assertStatus(422)
                ->assertJsonPath('message', 'Pengajuan termin PR aset tidak bisa dihapus; batalkan lewat modul Permintaan Pembelian');
        }

        $pengajuanDb = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertNull($pengajuanDb->dihapus_pada);
        $this->assertSame($termin[0]->id_termin, $pengajuanDb->id_termin_pembelian);
        $terminDb = DB::table('permintaan_pembelian_termin')->where('id_termin', $termin[0]->id_termin)->first();
        $this->assertSame($idPengajuan, $terminDb->id_pengajuan);
        $this->assertSame('menunggu', $terminDb->status);

        $this->actingAsRole('PENGADAAN');
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.termin.0.pengajuan.status', 'ditolak');
    }

    public function test_terima_pr_aset_ditolak_karena_penerimaan_per_unit(): void
    {
        $pr = $this->prAsetSampaiDibeli();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 2]],
        ])->assertStatus(422)->assertJsonPath('message', 'PR aset diterima per unit lewat tombol Daftarkan Unit');
        $this->assertSame('dibeli', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
        $this->assertNull(DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][0]['id_item'])->value('qty_diterima'));
    }

    public function test_dibeli_dan_pengajuan_termin_tenant_lain_404(): void
    {
        $pr = $this->prAsetSampaiDiproses();
        $payload = $this->payloadDibeli($pr);
        [, $penggunaLain] = $this->makeTenantLain();
        \Laravel\Sanctum\Sanctum::actingAs($penggunaLain, ['*']);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(404);
        $this->assertSame('diproses', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200);
        $idPengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->value('id_pengajuan');
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['status' => 'siap_transfer']);
        \Laravel\Sanctum\Sanctum::actingAs($penggunaLain, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => '2026-10-05', 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ])->assertStatus(404);
        $this->assertSame('menunggu', DB::table('permintaan_pembelian_termin')->where('id_pengajuan', $idPengajuan)->value('status'));
    }

    private function prAsetDuaItemSampaiDibeli(): array
    {
        $pr = $this->prAsetSampaiDiproses(['items' => [
            $this->itemAset(),
            $this->itemAset(['merk' => 'Isuzu', 'model' => 'Elf', 'tahun' => 2025, 'qty' => 1, 'harga_estimasi' => 400000000]),
        ]]);
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $this->payloadDibeli($pr, [
            'items'  => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 350000000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 400000000],
            ],
            'termin' => [
                ['nama' => 'DP', 'nominal' => 300000000, 'jatuh_tempo' => '2026-10-05'],
                ['nama' => 'Pelunasan', 'nominal' => 800000000, 'jatuh_tempo' => null],
            ],
        ]))->assertStatus(200)->json('data');
    }

    private function payloadArmada(?string $idItem, array $override = []): array
    {
        return array_merge([
            'nopol'                        => 'B ' . random_int(1000, 9999) . ' ' . Str::upper(Str::random(3)),
            'merk'                         => 'Hino',
            'model'                        => 'Dutro',
            'tahun'                        => 2026,
            'kondisi_beli'                 => 'baru',
            'id_permintaan_pembelian_item' => $idItem,
        ], $override);
    }

    private function daftarkanUnit(?string $idItem, array $override = [])
    {
        $this->actingAsRole('SUPERADMIN');
        return $this->postJson('/api/armada', $this->payloadArmada($idItem, $override));
    }

    private function terminPr(string $idPermintaan)
    {
        return DB::table('permintaan_pembelian_termin')->where('id_permintaan', $idPermintaan)->orderBy('urutan')->get();
    }

    private function headerPr(string $idPermintaan): object
    {
        return DB::table('permintaan_pembelian')->where('id_permintaan', $idPermintaan)->first();
    }

    private function qtyDiterima(string $idItem): int
    {
        return (int) DB::table('permintaan_pembelian_item')->where('id_item', $idItem)->value('qty_diterima');
    }

    public function test_daftarkan_unit_menaikkan_qty_diterima_lalu_pr_diterima_dan_selesai_saat_transfer_terakhir(): void
    {
        $pr = $this->prAsetDuaItemSampaiDibeli();
        $idItemHino = $pr['items'][0]['id_item'];
        $idItemIsuzu = $pr['items'][1]['id_item'];

        $armada1 = $this->daftarkanUnit($idItemHino, ['nopol' => 'B 1001 HNO'])->assertStatus(201)->json('data');
        $this->assertSame($idItemHino, $armada1['id_permintaan_pembelian_item']);
        $this->assertSame($idItemHino, DB::table('armada')->where('id_armada', $armada1['id_armada'])->value('id_permintaan_pembelian_item'));
        $this->assertSame(1, $this->qtyDiterima($idItemHino));
        $this->assertSame(0, $this->qtyDiterima($idItemIsuzu));
        $this->assertSame('dibeli', $this->headerPr($pr['id_permintaan'])->status);

        $this->actingAsRole('PENGADAAN');
        $detail = $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->json('data');
        $this->assertSame(1, $detail['items'][0]['qty_diterima']);
        $this->assertSame([['id_armada' => $armada1['id_armada'], 'nopol' => 'B 1001 HNO']], $detail['items'][0]['armada_terdaftar']);
        $this->assertSame([], $detail['items'][1]['armada_terdaftar']);

        $this->daftarkanUnit($idItemHino)->assertStatus(201);
        $this->assertSame(2, $this->qtyDiterima($idItemHino));
        $this->assertSame('dibeli', $this->headerPr($pr['id_permintaan'])->status);

        $jumlahArmada = DB::table('armada')->whereNull('dihapus_pada')->count();
        $this->daftarkanUnit($idItemHino)->assertStatus(422)->assertJsonPath('message', 'Semua unit item ini sudah terdaftar');
        $this->assertSame(2, $this->qtyDiterima($idItemHino));
        $this->assertSame($jumlahArmada, DB::table('armada')->whereNull('dihapus_pada')->count());

        $this->daftarkanUnit($idItemIsuzu)->assertStatus(201);
        $header = $this->headerPr($pr['id_permintaan']);
        $this->assertSame('diterima', $header->status);
        $this->assertSame(now()->toDateString(), $header->tanggal_diterima);
        $this->assertSame('Semua unit terdaftar di master Armada', $header->keterangan_penerimaan);
        $this->assertNotNull($header->diterima_oleh);
        $this->assertNotNull($header->diterima_pada);
        $this->assertNull($header->tanggal_pembayaran);
        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $pr['id_pengaju'], 'judul' => "PR {$pr['nomor_permintaan']} sudah diterima"]);

        $this->daftarkanUnit($idItemIsuzu)->assertStatus(422)
            ->assertJsonPath('message', 'Unit hanya bisa didaftarkan saat PR berstatus dibeli (status saat ini: diterima)');

        $termin = $this->terminPr($pr['id_permintaan']);
        $this->transferPengajuan($termin[0]->id_pengajuan, '2026-10-05')->assertStatus(200);
        $this->assertSame('diterima', $this->headerPr($pr['id_permintaan'])->status);
        $this->transferPengajuan($termin[1]->id_pengajuan, '2026-11-01')->assertStatus(200);
        $header = $this->headerPr($pr['id_permintaan']);
        $this->assertSame('selesai', $header->status);
        $this->assertSame('2026-11-01', $header->tanggal_pembayaran);
    }

    public function test_semua_termin_lunas_lalu_unit_lengkap_langsung_selesai(): void
    {
        $pr = $this->prAsetSampaiDibeli();
        $idItem = $pr['items'][0]['id_item'];
        $termin = $this->terminPr($pr['id_permintaan']);
        $this->transferPengajuan($termin[0]->id_pengajuan, '2026-10-05')->assertStatus(200);
        $this->transferPengajuan($termin[1]->id_pengajuan, '2026-11-01')->assertStatus(200);
        $this->assertSame('dibeli', $this->headerPr($pr['id_permintaan'])->status);

        $this->daftarkanUnit($idItem)->assertStatus(201);
        $this->assertSame('dibeli', $this->headerPr($pr['id_permintaan'])->status);

        $this->daftarkanUnit($idItem)->assertStatus(201);
        $header = $this->headerPr($pr['id_permintaan']);
        $this->assertSame('selesai', $header->status);
        $this->assertSame(now()->toDateString(), $header->tanggal_diterima);
        $this->assertSame('2026-11-01', $header->tanggal_pembayaran);
        $this->assertSame(2, $this->qtyDiterima($idItem));

        $this->actingAsRole('PENGADAAN');
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.termin_lunas', true)
            ->assertJsonPath('data.items.0.qty_diterima', 2)
            ->assertJsonCount(2, 'data.items.0.armada_terdaftar');
    }

    public function test_daftarkan_unit_menolak_item_tenant_lain_pr_bukan_aset_dan_pr_belum_dibeli(): void
    {
        $pr = $this->prAsetSampaiDibeli();
        $idItem = $pr['items'][0]['id_item'];
        [, $penggunaLain] = $this->makeTenantLain();

        \Laravel\Sanctum\Sanctum::actingAs($penggunaLain, ['*']);
        $this->postJson('/api/armada', $this->payloadArmada($idItem, ['nopol' => 'B 2002 LAIN']))->assertStatus(404)
            ->assertJsonPath('message', 'Item permintaan tidak ditemukan');
        $this->assertSame(0, $this->qtyDiterima($idItem));
        $this->assertSame(0, DB::table('armada')->where('nopol', 'B 2002 LAIN')->count());

        $this->daftarkanUnit((string) Str::uuid(), ['nopol' => 'B 2003 XXX'])->assertStatus(404)
            ->assertJsonPath('message', 'Item permintaan tidak ditemukan');
        $this->assertSame(0, DB::table('armada')->where('nopol', 'B 2003 XXX')->count());

        $prDiproses = $this->prAsetSampaiDiproses();
        $this->daftarkanUnit($prDiproses['items'][0]['id_item'], ['nopol' => 'B 2004 XXX'])->assertStatus(422)
            ->assertJsonPath('message', 'Unit hanya bisa didaftarkan saat PR berstatus dibeli (status saat ini: diproses)');
        $this->assertSame(0, DB::table('armada')->where('nopol', 'B 2004 XXX')->count());

        $this->actingAsRole('DISPATCHER');
        $prUmum = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'Servis', 'tipe' => 'umum', 'alasan' => 'Rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000]],
        ])->assertStatus(201)->json('data');
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$prUmum['id_permintaan']}/proses")->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$prUmum['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$prUmum['id_permintaan']}/dibeli", [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => now()->toDateString(),
            'items' => [['id_item' => $prUmum['items'][0]['id_item'], 'harga_aktual' => 350000]],
        ])->assertStatus(200);
        $this->daftarkanUnit($prUmum['items'][0]['id_item'], ['nopol' => 'B 2005 XXX'])->assertStatus(422)
            ->assertJsonPath('message', 'Unit hanya bisa didaftarkan saat PR berstatus dibeli');
        $this->assertSame(0, $this->qtyDiterima($prUmum['items'][0]['id_item']));

        $this->daftarkanUnit(null, ['nopol' => 'B 2006 XXX'])->assertStatus(201)->assertJsonPath('data.id_permintaan_pembelian_item', null);
        $this->postJson('/api/armada', ['nopol' => 'B 2007 XXX'])->assertStatus(201);
    }

    public function test_hapus_armada_dari_pr_dibeli_menurunkan_qty_dan_dari_pr_diterima_ditolak(): void
    {
        $pr = $this->prAsetSampaiDibeli();
        $idItem = $pr['items'][0]['id_item'];

        $armada1 = $this->daftarkanUnit($idItem)->assertStatus(201)->json('data');
        $this->assertSame(1, $this->qtyDiterima($idItem));
        $this->deleteJson("/api/armada/{$armada1['id_armada']}")->assertStatus(200);
        $this->assertSame(0, $this->qtyDiterima($idItem));
        $this->assertNotNull(DB::table('armada')->where('id_armada', $armada1['id_armada'])->value('dihapus_pada'));

        $this->actingAsRole('PENGADAAN');
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.items.0.armada_terdaftar', []);

        $this->daftarkanUnit($idItem)->assertStatus(201);
        $armada3 = $this->daftarkanUnit($idItem)->assertStatus(201)->json('data');
        $this->assertSame('diterima', $this->headerPr($pr['id_permintaan'])->status);

        $this->deleteJson("/api/armada/{$armada3['id_armada']}")->assertStatus(422)
            ->assertJsonPath('message', "Unit ini sudah dikonfirmasi diterima pada PR {$pr['nomor_permintaan']}, tidak bisa dihapus");
        $this->assertNull(DB::table('armada')->where('id_armada', $armada3['id_armada'])->value('dihapus_pada'));
        $this->assertSame(2, $this->qtyDiterima($idItem));
        $this->assertSame('diterima', $this->headerPr($pr['id_permintaan'])->status);

        $armadaBebas = $this->daftarkanUnit(null)->assertStatus(201)->json('data');
        $this->deleteJson("/api/armada/{$armadaBebas['id_armada']}")->assertStatus(200);
    }

    public function test_batal_pr_aset_saat_dibeli_ditolak(): void
    {
        $pr = $this->prAsetSampaiDibeli();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/batal", ['alasan' => 'Batal'])->assertStatus(422)
            ->assertJsonPath('message', 'Permintaan tidak bisa dibatalkan pada status ini (status saat ini: dibeli)');
        $this->assertSame('dibeli', $this->headerPr($pr['id_permintaan'])->status);
        $this->assertSame(2, DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->whereNull('dihapus_pada')->count());
        $this->assertSame(2, DB::table('permintaan_pembelian_termin')->where('id_permintaan', $pr['id_permintaan'])->where('status', 'menunggu')->count());
    }
}
