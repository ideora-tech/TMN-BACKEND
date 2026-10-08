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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MenerbitkanPo;
use Tests\TestCase;

class PermintaanPembelianAjukanUlangPembayaranTest extends TestCase
{
    use RefreshDatabase;
    use MenerbitkanPo;

    private const TOTAL_PO = 889130.0;
    private const PESAN_BUKAN_DITOLAK = 'Pembayaran hanya bisa diajukan ulang saat pengajuannya ditolak';
    private const PESAN_BUKAN_PELAKU = 'Pembayaran diajukan ulang oleh tim Pengadaan atau pelaku pembeliannya';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeSupplier(string $nama = 'Toko ATK Jaya'): string
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

    private function makeSparepart(string $nama, float $hargaStandar): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'SP-' . Str::random(6), 'nama' => $nama, 'satuan' => 'pcs',
            'harga_standar' => $hargaStandar, 'stok' => 2, 'aktif' => 1, 'dibuat_pada' => now(),
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

    private function unggahNota(array $pr): TestResponse
    {
        return $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", [
            'tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ]);
    }

    private function prUmumDibeli(): array
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
        $this->unggahNota($pr)->assertStatus(200);
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200)->json('data');
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

    private function pengajuan(array $pr): object
    {
        return DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->whereNull('dihapus_pada')->first();
    }

    private function pengajuanById(string $id): object
    {
        return DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
    }

    private function header(array $pr): object
    {
        return DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->first();
    }

    private function tolak(string $idPengajuan, string $alasan): TestResponse
    {
        $this->actingAsRole('SUPERADMIN');
        return $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/tolak", ['alasan' => $alasan]);
    }

    private function ajukanUlang(array $pr, array $payload, string $peran = 'PENGADAAN'): TestResponse
    {
        $this->actingAsRole($peran);
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/ajukan-ulang-pembayaran", $payload);
    }

    private function transfer(string $idPengajuan): TestResponse
    {
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['status' => 'siap_transfer']);
        $this->actingAsRole('KEUANGAN');
        return $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ]);
    }

    private function riwayat(array $pr): array
    {
        $this->actingAsRole('PENGADAAN');
        return $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->json('data.pengajuan_keuangan.riwayat');
    }

    public function test_pr_umum_ditolak_lalu_diajukan_ulang_tanpa_koreksi_sampai_lunas(): void
    {
        $pr = $this->prUmumDibeli();
        $this->terima($pr, 10, 1)->assertStatus(200)->assertJsonPath('data.status', 'diterima');
        $pengajuan = $this->pengajuan($pr);
        $this->assertSame('disetujui', $pengajuan->status);

        $this->ajukanUlang($pr, ['catatan' => 'Belum ditolak'])->assertStatus(422)->assertJsonPath('message', self::PESAN_BUKAN_DITOLAK);

        $this->travel(5)->minutes();
        $penolak = $this->actingAsRole('SUPERADMIN');
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/tolak", ['alasan' => 'Nota tidak terbaca'])->assertStatus(200);
        $this->assertDatabaseHas('pengajuan_pengeluaran_riwayat', [
            'id_pengajuan' => $pengajuan->id_pengajuan, 'jenis' => 'ditolak', 'keterangan' => 'Nota tidak terbaca', 'oleh' => $penolak->id_pengguna, 'urutan' => 1,
        ]);

        $this->actingAsRole('PENGADAAN');
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.pengajuan_keuangan.status', 'ditolak')
            ->assertJsonPath('data.pengajuan_keuangan.alasan_ditolak', 'Nota tidak terbaca');

        $this->ajukanUlang($pr, ['catatan' => 'Nota diganti'], 'DISPATCHER')->assertStatus(422)->assertJsonPath('message', self::PESAN_BUKAN_PELAKU);
        $this->ajukanUlang($pr, [])->assertStatus(422)->assertJsonValidationErrors(['catatan']);
        $this->ajukanUlang($pr, ['catatan' => 'ok'])->assertStatus(422)->assertJsonValidationErrors(['catatan']);
        $this->assertSame('ditolak', $this->pengajuan($pr)->status);

        $this->travel(5)->minutes();
        $res = $this->ajukanUlang($pr, ['catatan' => 'Nota diganti yang lebih jelas'])->assertStatus(200);
        $res->assertJsonPath('message', 'Pembayaran diajukan ulang')
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.pengajuan_keuangan.id_pengajuan', $pengajuan->id_pengajuan)
            ->assertJsonPath('data.pengajuan_keuangan.status', 'disetujui')
            ->assertJsonPath('data.pengajuan_keuangan.alasan_ditolak', null);

        $setelah = $this->pengajuan($pr);
        $this->assertSame($pengajuan->id_pengajuan, $setelah->id_pengajuan);
        $this->assertSame('disetujui', $setelah->status);
        $this->assertNull($setelah->alasan_ditolak);
        $this->assertSame(self::TOTAL_PO, (float) $setelah->nominal);
        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->count());
        $this->assertSame(self::TOTAL_PO, (float) $this->header($pr)->total_aktual);

        $riwayat = $this->riwayat($pr);
        $status = array_column($riwayat, 'status');
        $this->assertSame(['diajukan', 'disetujui_final', 'ditolak_final', 'diajukan_ulang', 'disetujui_final'], $status);
        $this->assertSame('Nota tidak terbaca', $riwayat[2]['keterangan']);
        $this->assertSame($penolak->username, $riwayat[2]['oleh']);
        $this->assertSame('Nota diganti yang lebih jelas', $riwayat[3]['keterangan']);
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $penolak->id_pengguna, 'tipe' => 'pengajuan_diajukan_ulang', 'referensi_id' => $pengajuan->id_pengajuan,
        ]);

        $this->ajukanUlang($pr, ['catatan' => 'Dua kali'])->assertStatus(422)->assertJsonPath('message', self::PESAN_BUKAN_DITOLAK);

        $this->transfer($pengajuan->id_pengajuan)->assertStatus(200);
        $this->assertSame('selesai', $this->header($pr)->status);
        $this->ajukanUlang($pr, ['catatan' => 'Sudah lunas'])->assertStatus(422);
    }

    public function test_ajukan_ulang_dengan_koreksi_memperbarui_rincian_pr_nilai_stok_dan_nominal_pengajuan(): void
    {
        $pr = $this->prUmumDibeli();
        $this->terima($pr, 10, 1)->assertStatus(200);
        $idBarang = $pr['items'][0]['id_barang'];
        $this->assertSame(52000.0, (float) DB::table('barang')->where('id_barang', $idBarang)->value('harga_standar'));
        $this->assertSame(52000.0, (float) DB::table('barang_mutasi')->where('id_permintaan_pembelian', $pr['id_permintaan'])->value('harga'));
        $pengajuan = $this->pengajuan($pr);
        $this->tolak($pengajuan->id_pengajuan, 'Harga tidak sesuai nota')->assertStatus(200);

        $items = [
            ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 50000],
            ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 350000],
        ];
        $this->ajukanUlang($pr, ['catatan' => 'Koreksi harga', 'items' => [$items[0]]])
            ->assertStatus(422)->assertJsonPath('message', 'Harga aktual semua item wajib diisi');
        $this->ajukanUlang($pr, ['catatan' => 'Koreksi harga', 'items' => [$items[0], ['id_item' => (string) Str::uuid(), 'harga_aktual' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', 'Item tidak ditemukan pada permintaan ini');
        $this->ajukanUlang($pr, ['catatan' => 'Koreksi harga', 'items' => $items, 'diskon' => 850001])
            ->assertStatus(422)->assertJsonPath('message', 'Diskon tidak boleh melebihi subtotal (Rp 850.000)');
        $this->ajukanUlang($pr, ['catatan' => 'Koreksi harga', 'diskon' => 870000, 'ongkir' => 0])
            ->assertStatus(422)->assertJsonPath('message', 'Total pembelian tidak boleh Rp 0');
        $this->assertSame('ditolak', $this->pengajuan($pr)->status);
        $this->assertSame(self::TOTAL_PO, (float) $this->pengajuan($pr)->nominal);
        $this->assertSame(52000.0, (float) DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][0]['id_item'])->value('harga_aktual'));

        $res = $this->ajukanUlang($pr, ['catatan' => 'Harga disamakan dengan nota', 'items' => $items, 'diskon' => 0, 'ppn_persen' => 11, 'ongkir' => 0])->assertStatus(200);
        $res->assertJsonPath('data.items.0.harga_aktual', 50000)->assertJsonPath('data.pengajuan_keuangan.status', 'disetujui');
        $this->assertSame(850000.0, (float) $res->json('data.subtotal_aktual'));
        $this->assertSame(0.0, (float) $res->json('data.diskon'));
        $this->assertSame(93500.0, (float) $res->json('data.ppn'));
        $this->assertSame(0.0, (float) $res->json('data.ongkir'));
        $this->assertSame(943500.0, (float) $res->json('data.total_aktual'));

        $this->assertSame(943500.0, (float) $this->header($pr)->total_aktual);
        $this->assertSame(943500.0, (float) $this->pengajuan($pr)->nominal);
        $this->assertSame(50000.0, (float) DB::table('barang')->where('id_barang', $idBarang)->value('harga_standar'));
        $this->assertSame(50000.0, (float) DB::table('barang_mutasi')->where('id_permintaan_pembelian', $pr['id_permintaan'])->value('harga'));
        $this->assertSame(10, (int) DB::table('barang')->where('id_barang', $idBarang)->value('stok'));
        $this->assertDatabaseHas('pengajuan_pengeluaran_riwayat', [
            'id_pengajuan' => $pengajuan->id_pengajuan, 'jenis' => 'diajukan_ulang', 'keterangan' => 'Harga disamakan dengan nota', 'nominal' => 943500,
        ]);
    }

    public function test_koreksi_tidak_menimpa_harga_standar_yang_sudah_berubah_sejak_pembelian(): void
    {
        $pr = $this->prUmumDibeli();
        $this->terima($pr, 10, 1)->assertStatus(200);
        $idBarang = $pr['items'][0]['id_barang'];
        DB::table('barang')->where('id_barang', $idBarang)->update(['harga_standar' => 60000]);
        $this->tolak($this->pengajuan($pr)->id_pengajuan, 'Harga salah')->assertStatus(200);

        $this->ajukanUlang($pr, ['catatan' => 'Koreksi harga', 'items' => [
            ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 50000],
            ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 350000],
        ]])->assertStatus(200);

        $this->assertSame(60000.0, (float) DB::table('barang')->where('id_barang', $idBarang)->value('harga_standar'));
        $this->assertSame(50000.0, (float) DB::table('barang_mutasi')->where('id_permintaan_pembelian', $pr['id_permintaan'])->value('harga'));
    }

    public function test_koreksi_setelah_sisa_ditutup_memakai_jumlah_yang_diterima(): void
    {
        $pr = $this->prUmumDibeli();
        $this->terima($pr, 4, 1)->assertStatus(200)->assertJsonPath('data.status', 'diterima_sebagian');
        $pengajuan = $this->pengajuan($pr);
        $this->tolak($pengajuan->id_pengajuan, 'Tunggu barang lengkap')->assertStatus(200);

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/tutup-sisa", ['alasan' => 'Stok supplier habis'])->assertStatus(200)
            ->assertJsonPath('message', 'Sisa ditutup — pengajuan pembayarannya berstatus ditolak, ajukan ulang pembayarannya');
        $this->assertSame('ditolak', $this->pengajuan($pr)->status);
        $this->assertSame(577442.0, (float) $this->pengajuan($pr)->nominal);

        $res = $this->ajukanUlang($pr, ['catatan' => 'Ongkir ditanggung supplier', 'ongkir' => 0])->assertStatus(200);
        $this->assertSame(558000.0, (float) $res->json('data.subtotal_aktual'));
        $this->assertSame(55800.0, (float) $res->json('data.diskon'));
        $this->assertSame(55242.0, (float) $res->json('data.ppn'));
        $this->assertSame(557442.0, (float) $res->json('data.total_aktual'));
        $this->assertSame(557442.0, (float) $this->pengajuan($pr)->nominal);
        $this->assertSame('disetujui', $this->pengajuan($pr)->status);

        $riwayat = array_column($this->riwayat($pr), 'status');
        $this->assertSame(1, count(array_keys($riwayat, 'ditolak_final', true)));
        $this->assertContains('diajukan_ulang', $riwayat);
    }

    public function test_ajukan_ulang_setelah_ditolak_approver_membuka_putaran_approval_baru(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'mgr_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengadaan', 'nama' => 'Approval Pengadaan', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType, 'tipe' => 'pengguna', 'id_pengguna' => $approver->id_pengguna, 'dibuat_pada' => now(),
        ]);
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000);

        $pr = $this->prUmumDibeli();
        $pengajuan = $this->pengajuan($pr);
        $this->assertSame('menunggu_approval', $pengajuan->status);

        $this->travel(5)->minutes();
        Sanctum::actingAs($approver, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/approval", ['keputusan' => 'tolak', 'catatan' => 'Harga tidak sesuai nota'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak');
        $this->assertDatabaseHas('pengajuan_pengeluaran_riwayat', [
            'id_pengajuan' => $pengajuan->id_pengajuan, 'jenis' => 'ditolak', 'keterangan' => 'Harga tidak sesuai nota', 'oleh' => $approver->id_pengguna,
        ]);

        $this->travel(5)->minutes();
        $this->ajukanUlang($pr, ['catatan' => 'Nota sudah direvisi supplier'])->assertStatus(200)
            ->assertJsonPath('data.pengajuan_keuangan.status', 'menunggu_approval')
            ->assertJsonPath('data.pengajuan_keuangan.menunggu.tahap', 'approval');

        $approval = DB::table('approval_pengajuan')->where('id_referensi', $pengajuan->id_pengajuan)->whereNull('dihapus_pada')->orderBy('dibuat_pada')->pluck('status')->all();
        $this->assertSame(['ditolak', 'menunggu'], $approval);

        $status = array_column($this->riwayat($pr), 'status');
        $this->assertSame(['diajukan', 'ditolak', 'ditolak_final', 'diajukan_ulang'], $status);

        $this->travel(5)->minutes();
        Sanctum::actingAs($approver, ['*']);
        $this->getJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}")->assertStatus(200)
            ->assertJsonCount(1, 'data.approval')
            ->assertJsonPath('data.approval.0.status', 'menunggu')
            ->assertJsonPath('data.approval_progress.disetujui', 0)
            ->assertJsonPath('data.approval_progress.total', 1)
            ->assertJsonPath('data.bisa_approve', true);
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');
        $this->getJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}")->assertStatus(200)
            ->assertJsonCount(1, 'data.approval')
            ->assertJsonPath('data.approval_progress.disetujui', 1)
            ->assertJsonPath('data.approval_progress.total', 1)
            ->assertJsonPath('data.bisa_approve', false);
        $this->assertSame(1, DB::table('pengajuan_pengeluaran_riwayat')->where('id_pengajuan', $pengajuan->id_pengajuan)->where('jenis', 'ditolak')->count());
    }

    private function prSparepart(): array
    {
        $this->actingAsRole('DISPATCHER');
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

    private function itemsSparepart(array $pr, array $harga): array
    {
        return array_map(fn ($i, $h) => ['id_item' => $i['id_item'], 'harga_aktual' => $h], $pr['items'], $harga);
    }

    private function ps(array $pr): object
    {
        return DB::table('pembelian_sparepart')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
    }

    public function test_pr_sparepart_lewat_po_diajukan_ulang_dengan_koreksi_menyamakan_pembelian_sparepart(): void
    {
        $pr = $this->prSparepart();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $this->terbitkanPo($pr['id_permintaan'], [
            'id_supplier' => $this->makeSupplier('Toko Onderdil'), 'tanggal_po' => now()->toDateString(),
            'items' => $this->itemsSparepart($pr, [90000, 210000]), 'diskon' => 40000, 'ppn_persen' => 11, 'ongkir' => 15000,
        ])->assertStatus(200);
        $this->unggahNota($pr)->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/realisasi-sparepart", [
            'tanggal_pembelian' => now()->toDateString(), 'items' => $this->itemsSparepart($pr, [90000, 210000]),
        ])->assertStatus(200)->assertJsonPath('data.status', 'diterima');

        $ps = $this->ps($pr);
        $buktiPsSebelum = DB::table('pembelian_sparepart_bukti')->where('id_pembelian', $ps->id_pembelian)->whereNull('dihapus_pada')->count();
        $pengajuan = $this->pengajuan($pr);
        $this->assertSame(403500.0, (float) $pengajuan->nominal);
        $this->unggahNota($pr)->assertStatus(422)->assertJsonPath('message', 'Nota pembelian hanya bisa diunggah sebelum realisasi');

        $this->tolak($pengajuan->id_pengajuan, 'Harga filter oli salah ketik')->assertStatus(200);
        $this->assertSame('dibeli', $this->ps($pr)->status);

        $this->actingAsRole('PENGADAAN');
        $this->unggahNota($pr)->assertStatus(200);
        $res = $this->ajukanUlang($pr, [
            'catatan' => 'Harga filter oli disamakan dengan nota',
            'items'   => $this->itemsSparepart($pr, [95000, 210000]),
        ])->assertStatus(200);
        $res->assertJsonPath('data.status', 'diterima')->assertJsonPath('data.pengajuan_keuangan.status', 'disetujui');
        $this->assertSame(400000.0, (float) $res->json('data.subtotal_aktual'));
        $this->assertSame(414600.0, (float) $res->json('data.total_aktual'));

        $psSetelah = $this->ps($pr);
        $this->assertSame('dibeli', $psSetelah->status);
        $this->assertSame(414600.0, (float) $psSetelah->total_aktual);
        $this->assertSame(40000.0, (float) $psSetelah->diskon);
        $this->assertSame(39600.0, (float) $psSetelah->ppn);
        $this->assertSame(15000.0, (float) $psSetelah->ongkir);
        $this->assertSame(95000.0, (float) DB::table('pembelian_sparepart_item')->where('id_pembelian', $ps->id_pembelian)->where('id_item_permintaan', $pr['items'][0]['id_item'])->value('harga_aktual'));
        $this->assertSame(95000.0, (float) DB::table('sparepart_mutasi')->where('id_pembelian', $ps->id_pembelian)->where('id_sparepart', $pr['items'][0]['id_sparepart'])->value('harga'));
        $this->assertSame(210000.0, (float) DB::table('sparepart_mutasi')->where('id_pembelian', $ps->id_pembelian)->where('id_sparepart', $pr['items'][1]['id_sparepart'])->value('harga'));
        $this->assertSame(4, (int) DB::table('sparepart')->where('id_sparepart', $pr['items'][0]['id_sparepart'])->value('stok'));
        $this->assertSame($buktiPsSebelum + 1, DB::table('pembelian_sparepart_bukti')->where('id_pembelian', $ps->id_pembelian)->whereNull('dihapus_pada')->count());
        $this->assertSame(414600.0, (float) $this->pengajuan($pr)->nominal);

        $this->actingAsRole('PENGADAAN');
        $this->unggahNota($pr)->assertStatus(422)->assertJsonPath('message', 'Nota pembelian hanya bisa diunggah sebelum realisasi');

        $this->transfer($pengajuan->id_pengajuan)->assertStatus(200);
        $this->assertSame('selesai', $this->header($pr)->status);
        $this->assertSame('lunas', $this->ps($pr)->status);
    }

    public function test_pr_sparepart_mandiri_boleh_diajukan_ulang_pelaku_pembelian_selama_dalam_batas(): void
    {
        $pr = $this->prSparepart();
        $pengaju = Pengguna::findOrFail($pr['id_pengaju']);
        Sanctum::actingAs($pengaju, ['*']);
        $this->unggahNota($pr)->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/realisasi-sparepart", [
            'tanggal_pembelian' => now()->toDateString(), 'items' => $this->itemsSparepart($pr, [90000, 210000]), 'ppn_persen' => 11, 'ongkir' => 5000,
        ])->assertStatus(200)->assertJsonPath('data.status', 'diterima')->assertJsonPath('data.dibeli_oleh', $pengaju->id_pengguna);
        $pengajuan = $this->pengajuan($pr);
        $this->assertSame(437900.0, (float) $pengajuan->nominal);
        $this->tolak($pengajuan->id_pengajuan, 'Nota buram')->assertStatus(200);

        $this->ajukanUlang($pr, ['catatan' => 'Bukan pembelinya'], 'DISPATCHER')->assertStatus(422)->assertJsonPath('message', self::PESAN_BUKAN_PELAKU);

        Sanctum::actingAs($pengaju, ['*']);
        $url = "/api/permintaan-pembelian/{$pr['id_permintaan']}/ajukan-ulang-pembayaran";
        $this->patchJson($url, ['catatan' => 'Harga dikoreksi', 'items' => $this->itemsSparepart($pr, [150000, 210000])])
            ->assertStatus(422)->assertJsonPath('message', 'Total Rp 571.100 melebihi batas mandiri Rp 500.000, koreksi harus oleh tim Pengadaan');
        $this->assertSame('ditolak', $this->pengajuan($pr)->status);

        $this->patchJson($url, ['catatan' => 'Nota difoto ulang'])->assertStatus(200)
            ->assertJsonPath('data.pengajuan_keuangan.status', 'disetujui');
        $this->assertSame(437900.0, (float) $this->pengajuan($pr)->nominal);
    }

    private function prAsetDibeli(): array
    {
        $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'Pengadaan 2 unit truk', 'tipe' => 'aset', 'alasan' => 'Ekspansi armada', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [[
                'jenis' => 'aset', 'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
                'merk' => 'Hino', 'model' => 'Dutro', 'tahun' => 2026, 'qty' => 2, 'harga_estimasi' => 350000000,
            ]],
        ])->assertStatus(201)->json('data');
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $payload = [
            'id_supplier' => $this->makeSupplier('Dealer Hino Jaya'), 'tanggal_pembelian' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 350000000]],
            'termin' => [
                ['nama' => 'DP 30%', 'nominal' => 210000000, 'jatuh_tempo' => null],
                ['nama' => 'Pelunasan', 'nominal' => 490000000, 'jatuh_tempo' => null],
            ],
        ];
        $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(200);
        $this->unggahNota($pr)->assertStatus(200);
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200)->json('data');
    }

    public function test_termin_pr_aset_yang_ditolak_diajukan_ulang_per_termin_tanpa_mengubah_nominal(): void
    {
        $pr = $this->prAsetDibeli();
        $dp = $pr['termin'][0];
        $pelunasan = $pr['termin'][1];
        $this->tolak($dp['pengajuan']['id_pengajuan'], 'BPKB belum dilampirkan')->assertStatus(200);

        $this->actingAsRole('PENGADAAN');
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)
            ->assertJsonPath('data.termin.0.pengajuan.status', 'ditolak')
            ->assertJsonPath('data.termin.0.pengajuan.alasan_ditolak', 'BPKB belum dilampirkan');

        $this->ajukanUlang($pr, ['catatan' => 'Dokumen dilengkapi'])
            ->assertStatus(422)->assertJsonPath('message', 'Pilih termin yang pembayarannya ditolak');
        $this->ajukanUlang($pr, ['catatan' => 'Dokumen dilengkapi', 'id_termin' => $pelunasan['id_termin']])
            ->assertStatus(422)->assertJsonPath('message', 'Termin hanya bisa diajukan ulang saat pengajuannya ditolak');
        $this->ajukanUlang($pr, ['catatan' => 'Dokumen dilengkapi', 'id_termin' => $dp['id_termin'], 'ongkir' => 1000])
            ->assertStatus(422)->assertJsonPath('message', 'Nominal termin PR aset tidak bisa dikoreksi lewat pengajuan ulang');
        $this->ajukanUlang($pr, ['catatan' => 'Dokumen dilengkapi', 'id_termin' => $dp['id_termin']], 'DISPATCHER')
            ->assertStatus(422)->assertJsonPath('message', self::PESAN_BUKAN_PELAKU);
        $this->assertSame('ditolak', $this->pengajuanById($dp['pengajuan']['id_pengajuan'])->status);

        $this->ajukanUlang($pr, ['catatan' => 'Dokumen dilengkapi', 'id_termin' => $dp['id_termin']])->assertStatus(200)
            ->assertJsonPath('data.termin.0.pengajuan.status', 'disetujui')
            ->assertJsonPath('data.termin.1.pengajuan.status', 'disetujui');
        $setelah = $this->pengajuanById($dp['pengajuan']['id_pengajuan']);
        $this->assertSame('disetujui', $setelah->status);
        $this->assertSame(210000000.0, (float) $setelah->nominal);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $pelunasan['pengajuan']['id_pengajuan'])->update(['nominal' => 1000]);
        $this->tolak($pelunasan['pengajuan']['id_pengajuan'], 'Nominal tidak sesuai termin')->assertStatus(200);
        $this->ajukanUlang($pr, ['catatan' => 'Nominal dikembalikan', 'id_termin' => $pelunasan['id_termin']])->assertStatus(200);
        $this->assertSame(490000000.0, (float) $this->pengajuanById($pelunasan['pengajuan']['id_pengajuan'])->nominal);
        $this->assertNull($setelah->alasan_ditolak);
        $this->assertSame(2, DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->whereNull('dihapus_pada')->count());
    }

    public function test_pengajuan_pembayaran_pr_tidak_bisa_diubah_atau_dihapus_dari_arus_kas(): void
    {
        $pr = $this->prUmumDibeli();
        $pengajuan = $this->pengajuan($pr);
        $this->tolak($pengajuan->id_pengajuan, 'Nota salah')->assertStatus(200);

        $this->actingAsRole('SUPERADMIN');
        $this->deleteJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}")->assertStatus(422)
            ->assertJsonPath('message', 'Pengajuan pembayaran PR tidak bisa dihapus — bila ditolak, ajukan ulang dari halaman Permintaan Pembelian');
        $this->putJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}", ['nominal' => 1000])->assertStatus(422)
            ->assertJsonPath('message', 'Pengajuan pembayaran PR diubah lewat halaman Permintaan Pembelian (Ajukan Ulang Pembayaran)');

        $setelah = $this->pengajuan($pr);
        $this->assertSame('ditolak', $setelah->status);
        $this->assertSame(self::TOTAL_PO, (float) $setelah->nominal);

        $aset = $this->prAsetDibeli();
        $idTermin = $aset['termin'][0]['pengajuan']['id_pengajuan'];
        $this->tolak($idTermin, 'Dokumen kurang')->assertStatus(200);
        $this->putJson("/api/arus-kas/pengajuan/{$idTermin}", ['nominal' => 1000])->assertStatus(422)
            ->assertJsonPath('message', 'Pengajuan pembayaran PR diubah lewat halaman Permintaan Pembelian (Ajukan Ulang Pembayaran)');
        $this->assertSame(210000000.0, (float) $this->pengajuanById($idTermin)->nominal);
    }

    public function test_ajukan_ulang_dari_halaman_basi_ditolak_409(): void
    {
        $pr = $this->prUmumDibeli();
        $this->terima($pr, 4, 1)->assertStatus(200);
        $pengajuan = $this->pengajuan($pr);
        $this->tolak($pengajuan->id_pengajuan, 'Tunggu barang lengkap')->assertStatus(200);

        $this->actingAsRole('PENGADAAN');
        $versiLama = $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->json('data.pengajuan_keuangan.versi');
        $this->assertNotSame('', $versiLama);

        $this->travel(5)->minutes();
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/tutup-sisa", ['alasan' => 'Stok supplier habis'])->assertStatus(200);
        $this->assertSame(577442.0, (float) $this->pengajuan($pr)->nominal);

        $this->ajukanUlang($pr, ['catatan' => 'Dari halaman lama', 'versi_pengajuan' => $versiLama])->assertStatus(409)
            ->assertJsonPath('message', 'Pengajuan pembayaran ini baru saja berubah — muat ulang halaman lalu periksa lagi sebelum mengajukan ulang');
        $this->ajukanUlang($pr, ['catatan' => 'Dari halaman lama', 'versi_pengajuan' => $versiLama, 'diskon' => 87000])->assertStatus(409);
        $this->assertSame('ditolak', $this->pengajuan($pr)->status);
        $this->assertSame(577442.0, (float) $this->header($pr)->total_aktual);
        $this->assertSame(55800.0, (float) $this->header($pr)->diskon);

        $this->actingAsRole('PENGADAAN');
        $versiBaru = $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->json('data.pengajuan_keuangan.versi');
        $this->assertNotSame($versiLama, $versiBaru);
        $this->ajukanUlang($pr, ['catatan' => 'Sudah dimuat ulang', 'versi_pengajuan' => $versiBaru])->assertStatus(200);
        $this->assertSame(577442.0, (float) $this->pengajuan($pr)->nominal);
        $this->assertSame('disetujui', $this->pengajuan($pr)->status);
    }

    public function test_pr_tanpa_pengajuan_pembayaran_bisa_dibuatkan_ulang(): void
    {
        $pr = $this->prUmumDibeli();
        $this->terima($pr, 10, 1)->assertStatus(200);
        $lama = $this->pengajuan($pr);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $lama->id_pengajuan)->update(['dihapus_pada' => now()]);

        $this->ajukanUlang($pr, ['catatan' => 'Pengajuan lama terhapus'], 'DISPATCHER')->assertStatus(422)->assertJsonPath('message', self::PESAN_BUKAN_PELAKU);
        $res = $this->ajukanUlang($pr, ['catatan' => 'Pengajuan lama terhapus'])->assertStatus(200);
        $res->assertJsonPath('data.pengajuan_keuangan.status', 'disetujui');

        $baru = $this->pengajuan($pr);
        $this->assertNotSame($lama->id_pengajuan, $baru->id_pengajuan);
        $this->assertSame(self::TOTAL_PO, (float) $baru->nominal);
        $this->assertSame('pengadaan', $baru->kategori);
        $this->assertDatabaseHas('pengajuan_pengeluaran_riwayat', [
            'id_pengajuan' => $baru->id_pengajuan, 'jenis' => 'diajukan_ulang', 'keterangan' => 'Pengajuan lama terhapus',
        ]);

        $this->transfer($baru->id_pengajuan)->assertStatus(200);
        $this->assertSame('selesai', $this->header($pr)->status);
    }

    public function test_koreksi_harga_barang_yang_sama_di_dua_baris_tidak_saling_menimpa(): void
    {
        $idBarang = $this->makeBarang();
        $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'Kertas dua gelombang', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $idBarang, 'nama_item' => 'Kertas A4', 'qty' => 2, 'satuan' => 'rim', 'harga_estimasi' => 10000],
                ['jenis' => 'barang', 'id_barang' => $idBarang, 'nama_item' => 'Kertas A4', 'qty' => 3, 'satuan' => 'rim', 'harga_estimasi' => 12000],
            ],
        ])->assertStatus(201)->json('data');
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $payload = [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 10000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 12000],
            ],
        ];
        $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(200);
        $this->unggahNota($pr)->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 2],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 3],
            ],
        ])->assertStatus(200)->assertJsonPath('data.status', 'diterima');
        $this->tolak($this->pengajuan($pr)->id_pengajuan, 'Harga salah')->assertStatus(200);

        $this->ajukanUlang($pr, ['catatan' => 'Harga dikoreksi', 'items' => [
            ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 12000],
            ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 15000],
        ]])->assertStatus(200);

        $mutasi = DB::table('barang_mutasi')->where('id_permintaan_pembelian', $pr['id_permintaan'])->orderBy('qty')->get(['qty', 'harga']);
        $this->assertSame([[2, 12000.0], [3, 15000.0]], $mutasi->map(fn ($m) => [(int) $m->qty, (float) $m->harga])->all());
        $this->assertSame(69000.0, (float) $this->pengajuan($pr)->nominal);

        $this->tolak($this->pengajuan($pr)->id_pengajuan, 'Masih salah')->assertStatus(200);
        DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][1]['id_item'])->update(['harga_aktual' => 12000]);
        DB::table('barang_mutasi')->where('id_permintaan_pembelian', $pr['id_permintaan'])->update(['harga' => 12000]);
        $this->ajukanUlang($pr, ['catatan' => 'Hanya satu baris', 'items' => [
            ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 11000],
            ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 12000],
        ]])->assertStatus(422)->assertJsonPath('message', 'Barang yang sama muncul di dua baris dengan harga lama yang sama — isi harga baru yang sama untuk keduanya');
        $this->assertSame('ditolak', $this->pengajuan($pr)->status);
        $this->assertSame(12000.0, (float) DB::table('permintaan_pembelian_item')->where('id_item', $pr['items'][0]['id_item'])->value('harga_aktual'));
    }

    public function test_daftar_pr_menandai_dan_menyaring_pembayaran_ditolak(): void
    {
        $ditolak = $this->prUmumDibeli();
        $lancar = $this->prUmumDibeli();
        $this->tolak($this->pengajuan($ditolak)->id_pengajuan, 'Nota salah')->assertStatus(200);

        $this->actingAsRole('PENGADAAN');
        $semua = $this->getJson('/api/permintaan-pembelian?limit=50')->assertStatus(200);
        $semua->assertJsonPath('meta.pembayaran_ditolak', 1)->assertJsonCount(2, 'data');
        $peta = collect($semua->json('data'))->pluck('pembayaran_ditolak', 'id_permintaan');
        $this->assertTrue($peta[$ditolak['id_permintaan']]);
        $this->assertFalse($peta[$lancar['id_permintaan']]);

        $this->getJson('/api/permintaan-pembelian?pembayaran_ditolak=1')->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_permintaan', $ditolak['id_permintaan']);

        $this->ajukanUlang($ditolak, ['catatan' => 'Nota diganti'])->assertStatus(200);
        $this->actingAsRole('PENGADAAN');
        $this->getJson('/api/permintaan-pembelian?pembayaran_ditolak=1')->assertStatus(200)
            ->assertJsonPath('meta.pembayaran_ditolak', 0)->assertJsonCount(0, 'data');
    }

    public function test_pengajuan_manual_yang_ditolak_lalu_diubah_tercatat_diajukan_ulang(): void
    {
        $this->actingAsRole('KEUANGAN');
        $id = $this->postJson('/api/arus-kas/pengajuan', [
            'kategori' => 'lainnya', 'nominal' => 250000, 'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Toko Sumber', 'keterangan' => 'Beli galon',
        ])->assertStatus(201)->json('data.id_pengajuan');
        $this->tolak($id, 'Keterangan kurang jelas')->assertStatus(200);

        $this->actingAsRole('KEUANGAN');
        $this->putJson("/api/arus-kas/pengajuan/{$id}", ['keterangan' => 'Beli galon air minum kantor'])->assertStatus(200);

        $jenis = DB::table('pengajuan_pengeluaran_riwayat')->where('id_pengajuan', $id)->orderBy('urutan')->pluck('jenis')->all();
        $this->assertSame(['ditolak', 'diajukan_ulang'], $jenis);
    }

    public function test_ajukan_ulang_pr_perusahaan_lain_404(): void
    {
        $pr = $this->prUmumDibeli();
        $this->tolak($this->pengajuan($pr)->id_pengajuan, 'Nota salah')->assertStatus(200);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penggunaLain = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($penggunaLain, ['*']);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/ajukan-ulang-pembayaran", ['catatan' => 'Bukan milik saya'])->assertStatus(404);
        $this->assertSame('ditolak', $this->pengajuan($pr)->status);
    }
}
