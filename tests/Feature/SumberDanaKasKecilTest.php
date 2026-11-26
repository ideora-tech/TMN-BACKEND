<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aturan sumber dana (Nov 2026): Arus Kas = kas bank. Bank hanya menerima
 * pembayaran klien + pemasukan lain, dan hanya mengeluarkan dana untuk
 * pengisian Kas Kecil. Semua pengajuan biaya dibayar dari saldo Kas Kecil.
 */
class SumberDanaKasKecilTest extends TestCase
{
    use RefreshDatabase;

    private const HARI = '2026-08-10';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        $service = app(ArusKasService::class);
        $service->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
        $service->setBatasRealisasiMandiri(self::PERUSAHAAN_ID, 999999999);
    }

    private function saldoAwalKasKecil(float $nominal, string $tanggal = '2026-08-01'): void
    {
        $this->postJson('/api/kas-kecil/saldo-awal', ['tanggal' => $tanggal, 'nominal' => $nominal])->assertStatus(201);
    }

    private function pengajuanSiapBayar(array $override = []): string
    {
        $id = (string) $this->postJson('/api/arus-kas/pengajuan', array_merge([
            'kategori'          => 'legalitas',
            'nominal'           => 300000,
            'tanggal_pengajuan' => '2026-08-05',
            'penerima'          => 'Samsat',
            'keterangan'        => 'Perpanjang STNK',
        ], $override))->assertStatus(201)->json('data.id_pengajuan');

        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertJsonPath('data.status', 'siap_transfer');

        return $id;
    }

    private function bayar(string $idPengajuan, string $tanggal = self::HARI): \Illuminate\Testing\TestResponse
    {
        return $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => $tanggal,
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 50, 'image/jpeg'),
        ], ['Accept' => 'application/json']);
    }

    private function saldoKasKecil(): float
    {
        return (float) $this->getJson('/api/kas-kecil/ringkasan')->assertStatus(200)->json('data.saldo');
    }

    private function rekapBank(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31')->assertStatus(200);
    }

    private function buatFakturLunas(float $total, string $tanggalFaktur, string $tanggalBayar, float $dibayar): string
    {
        $idFaktur = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $idFaktur, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_faktur' => 'FK-' . Str::random(6),
            'total' => $total, 'status' => 'terkirim', 'tanggal_faktur' => $tanggalFaktur, 'dibuat_pada' => now(),
        ]);
        DB::table('pembayaran_faktur')->insert([
            'id_pembayaran_faktur' => (string) Str::uuid(), 'id_faktur' => $idFaktur,
            'tanggal_bayar' => $tanggalBayar, 'nominal' => $dibayar, 'dibuat_pada' => now(),
        ]);

        return $idFaktur;
    }

    private function buatInvoiceVendor(float $total = 1000000): string
    {
        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendor, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => 'VDR-' . Str::random(6), 'nama_vendor' => 'Vendor Kas', 'dibuat_pada' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $idVendor,
            'nomor_invoice' => 'INV-' . Str::random(6), 'tanggal_invoice' => '2026-08-01', 'total' => $total,
            'status' => 'diverifikasi', 'status_pembayaran' => 'belum_bayar', 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    // ── Pembayaran pengajuan memotong Kas Kecil ─────────────────────────────

    public function test_pengajuan_biaya_dibayar_dari_kas_kecil(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->saldoAwalKasKecil(1000000);
        $id = $this->pengajuanSiapBayar(['nominal' => 300000]);

        $this->bayar($id)->assertStatus(200)
            ->assertJsonPath('data.status', 'ditransfer')
            ->assertJsonPath('data.sumber_dana', 'kas_kecil');

        $this->assertEquals(700000, $this->saldoKasKecil());
        $baris = DB::table('kas_kecil_transaksi')->where('id_pengajuan', $id)->first();
        $this->assertNotNull($baris);
        $this->assertSame('keluar', $baris->arah);
        $this->assertSame('pembayaran', $baris->jenis);
        $this->assertSame(self::HARI, substr((string) $baris->tanggal, 0, 10));
        $this->assertEquals(300000, (float) $baris->nominal);
        $this->assertSame('Samsat', $baris->penerima);
    }

    public function test_pembayaran_ditolak_bila_saldo_kas_kecil_kurang(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->saldoAwalKasKecil(100000);
        $id = $this->pengajuanSiapBayar(['nominal' => 300000]);

        $res = $this->bayar($id)->assertStatus(422);
        $this->assertStringContainsString('Saldo petty cash tidak cukup', (string) $res->json('errors.nominal.0'));

        $this->assertSame('siap_transfer', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->value('status'));
        $this->assertFalse(DB::table('kas_kecil_transaksi')->where('id_pengajuan', $id)->exists());
        $this->assertEquals(100000, $this->saldoKasKecil());
    }

    public function test_baris_kas_kecil_hasil_pembayaran_tidak_bisa_diubah_atau_dihapus(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->saldoAwalKasKecil(1000000);
        $id = $this->pengajuanSiapBayar();
        $this->bayar($id)->assertStatus(200);
        $idTransaksi = (string) DB::table('kas_kecil_transaksi')->where('id_pengajuan', $id)->value('id_transaksi');

        $this->putJson("/api/kas-kecil/transaksi/{$idTransaksi}", [
            'tanggal' => self::HARI, 'kategori' => 'atk', 'nominal' => 1000, 'keterangan' => 'ubah',
        ])->assertStatus(409);
        $this->deleteJson("/api/kas-kecil/transaksi/{$idTransaksi}")->assertStatus(409);
    }

    public function test_pengisian_kas_kecil_tetap_ditransfer_dari_rekening(): void
    {
        $this->actingAsRole('KEUANGAN');
        DB::table('approval_event_type')->insert([
            'id_event_type' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengisian_kas_kecil', 'nama' => 'Pengisian Petty Cash', 'mode_resolusi' => 'pinned',
            'aktif' => 0, 'dibuat_pada' => now(),
        ]);
        $idPengisian = (string) $this->postJson('/api/kas-kecil/pengisian', [
            'tanggal' => now()->toDateString(), 'nominal' => 2000000, 'keterangan' => 'Isi petty cash',
        ])->assertStatus(201)->json('data.id_pengisian');
        $idPengajuan = (string) DB::table('kas_kecil_pengisian')->where('id_pengisian', $idPengisian)->value('id_pengajuan');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);

        $this->bayar($idPengajuan, now()->toDateString())->assertStatus(200)->assertJsonPath('data.sumber_dana', 'rekening');

        $this->assertEquals(2000000, $this->saldoKasKecil());
        $this->assertFalse(DB::table('kas_kecil_transaksi')->where('id_pengajuan', $idPengajuan)->exists());
    }

    public function test_daftar_pengeluaran_kas_bank_hanya_pengisian_dan_histori_rekening(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->saldoAwalKasKecil(5000000);

        // Biaya baru: belum dibayar dan sudah dibayar dari Petty Cash — bukan uang keluar bank.
        $belumBayar = $this->pengajuanSiapBayar(['penerima' => 'Belum Bayar']);
        $dariPettyCash = $this->pengajuanSiapBayar(['penerima' => 'Dari Petty Cash']);
        $this->bayar($dariPettyCash)->assertStatus(200);

        // Histori lama: sudah ditransfer dari rekening sebelum aturan baru.
        $lama = $this->pengajuanSiapBayar(['penerima' => 'Histori Rekening']);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $lama)
            ->update(['status' => 'ditransfer', 'sumber_dana' => 'rekening', 'tanggal_transfer' => '2026-07-01']);

        // Pengisian Petty Cash: ditransfer dari bank.
        DB::table('approval_event_type')->insert([
            'id_event_type' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengisian_kas_kecil', 'nama' => 'Pengisian Petty Cash', 'mode_resolusi' => 'pinned',
            'aktif' => 0, 'dibuat_pada' => now(),
        ]);
        $idPengisian = (string) $this->postJson('/api/kas-kecil/pengisian', [
            'tanggal' => now()->toDateString(), 'nominal' => 2000000, 'keterangan' => 'Isi petty cash',
        ])->assertStatus(201)->json('data.id_pengisian');
        $pengisian = (string) DB::table('kas_kecil_pengisian')->where('id_pengisian', $idPengisian)->value('id_pengajuan');

        $idKasBank = collect($this->getJson('/api/arus-kas/pengajuan?sumber_dana=rekening')->assertStatus(200)->json('data'))
            ->pluck('id_pengajuan')->sort()->values()->all();
        $this->assertEquals(collect([$lama, $pengisian])->sort()->values()->all(), $idKasBank);

        // Tanpa filter (Proses Pembayaran) tetap semua.
        $this->assertCount(4, $this->getJson('/api/arus-kas/pengajuan')->json('data'));
        $this->assertNotContains($belumBayar, $idKasBank);
    }

    // ── Invoice vendor ──────────────────────────────────────────────────────

    public function test_pengajuan_invoice_vendor_dibayar_dari_kas_kecil_sekali_catat(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->saldoAwalKasKecil(5000000);
        $idInvoice = $this->buatInvoiceVendor(1000000);

        $idPengajuan = (string) $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran/ajukan", [
            'nominal' => 1000000, 'catatan' => 'Bayar sewa unit',
        ])->assertStatus(201)->json('data.id_pengajuan');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        $this->bayar($idPengajuan)->assertStatus(200);

        $this->assertSame('kas_kecil', DB::table('pembayaran_vendor')->where('id_invoice_vendor', $idInvoice)->value('sumber_dana'));
        $this->assertSame(1, DB::table('kas_kecil_transaksi')->where('arah', 'keluar')->count());
        $this->assertEquals(4000000, $this->saldoKasKecil());

        $bank = collect($this->rekapBank()->json('data.transaksi'));
        $this->assertCount(0, $bank->where('sumber', 'pembayaran_vendor'));
    }

    public function test_pembayaran_vendor_langsung_dicatat_di_kas_kecil_dan_terhapus_bersama(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->saldoAwalKasKecil(800000);
        $idInvoice = $this->buatInvoiceVendor(1000000);

        $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran", [
            'tanggal_bayar' => self::HARI, 'nominal' => 900000, 'metode' => 'transfer',
        ])->assertStatus(422);

        $idBayar = (string) $this->postJson("/api/invoice-vendor/{$idInvoice}/pembayaran", [
            'tanggal_bayar' => self::HARI, 'nominal' => 500000, 'metode' => 'transfer',
        ])->assertStatus(201)->json('data.id_pembayaran_vendor');

        $this->assertEquals(300000, $this->saldoKasKecil());
        $this->assertTrue(DB::table('kas_kecil_transaksi')->where('id_pembayaran_vendor', $idBayar)->whereNull('dihapus_pada')->exists());

        $this->deleteJson("/api/invoice-vendor/{$idInvoice}/pembayaran/{$idBayar}")->assertStatus(200);
        $this->assertEquals(800000, $this->saldoKasKecil());
    }

    // ── Rekap kas bank ──────────────────────────────────────────────────────

    public function test_rekap_bank_hanya_pembayaran_klien_pemasukan_dan_pengisian(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->saldoAwalKasKecil(1000000);
        $idBiaya = $this->pengajuanSiapBayar(['nominal' => 300000]);
        $this->bayar($idBiaya)->assertStatus(200);

        // Pengeluaran lama (sebelum aturan baru) tetap tercatat dari rekening
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pengajuan' => 'PP-LAMA-1',
            'kategori' => 'uang_jalan', 'nominal' => 400000, 'tanggal_pengajuan' => '2026-08-01', 'penerima' => 'Budi',
            'keterangan' => 'lama', 'status' => 'ditransfer', 'tanggal_transfer' => '2026-08-02', 'dibuat_pada' => now(),
        ]);
        // Faktur terbit Juli, dibayar sebagian Agustus: yang masuk bank hanya pembayarannya
        $this->buatFakturLunas(5000000, '2026-07-20', '2026-08-15', 3000000);
        // Faktur terbit Agustus belum dibayar: tidak masuk bank
        DB::table('faktur')->insert([
            'id_faktur' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_faktur' => 'FK-BELUM',
            'total' => 9000000, 'status' => 'terkirim', 'tanggal_faktur' => '2026-08-05', 'dibuat_pada' => now(),
        ]);

        $res = $this->rekapBank();
        $transaksi = collect($res->json('data.transaksi'));

        $this->assertCount(0, $transaksi->where('referensi.id', $idBiaya));
        $this->assertCount(1, $transaksi->where('referensi.label', 'PP-LAMA-1'));
        $faktur = $transaksi->where('sumber', 'faktur')->values();
        $this->assertCount(1, $faktur);
        $this->assertEquals(3000000, (float) $faktur[0]['nominal']);
        $this->assertSame('2026-08-15', substr((string) $faktur[0]['tanggal'], 0, 10));
    }

    // ── Laporan PSAK gabungan ───────────────────────────────────────────────

    public function test_laporan_psak_menggabungkan_bank_dan_kas_kecil_tanpa_dobel_pengisian(): void
    {
        $this->actingAsRole('KEUANGAN');
        // Saldo sebelum periode: bank 2jt (pembayaran klien Juli), kas kecil 1jt
        $this->buatFakturLunas(2000000, '2026-07-01', '2026-07-10', 2000000);
        $this->saldoAwalKasKecil(1000000, '2026-07-15');

        // Periode Agustus: klien bayar 3jt; pengisian kas kecil 500rb (internal);
        // uang jalan 300rb dibayar dari kas kecil; ATK 50rb dari kas kecil.
        $this->buatFakturLunas(3000000, '2026-08-01', '2026-08-03', 3000000);
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pengajuan' => 'PP-ISI',
            'kategori' => 'pengisian_kas_kecil', 'id_pengisian_kas_kecil' => (string) Str::uuid(), 'nominal' => 500000,
            'tanggal_pengajuan' => '2026-08-04', 'penerima' => 'Petty Cash', 'keterangan' => 'isi', 'status' => 'ditransfer',
            'tanggal_transfer' => '2026-08-04', 'sumber_dana' => 'rekening', 'dibuat_pada' => now(),
        ]);
        DB::table('kas_kecil_transaksi')->insert([
            'id_transaksi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_transaksi' => 'KK-ISI',
            'tanggal' => '2026-08-04', 'arah' => 'masuk', 'jenis' => 'pengisian', 'nominal' => 500000,
            'keterangan' => 'Pengisian', 'dibuat_pada' => now(),
        ]);
        $idUangJalan = $this->pengajuanSiapBayar(['kategori' => 'uang_jalan', 'nominal' => 300000, 'penerima' => 'Budi']);
        $this->bayar($idUangJalan)->assertStatus(200);
        $this->postJson('/api/kas-kecil/transaksi', [
            'tanggal' => self::HARI, 'kategori' => 'atk', 'nominal' => 50000, 'keterangan' => 'Kertas',
        ])->assertStatus(201);

        $data = app(ArusKasService::class)->laporanPsak(self::PERUSAHAAN_ID, '2026-08-01', '2026-08-31');
        $baris = collect($data['kelompok'][0]['baris'])->pluck('nominal', 'label');

        $this->assertEquals(3000000, $baris['Penerimaan dari pelanggan']);
        $this->assertEquals(300000, $baris['Pembayaran uang jalan']);
        $this->assertEquals(50000, $baris['Pengeluaran petty cash']);
        $this->assertArrayNotHasKey('Pengisian petty cash', $baris->all());
        $this->assertEquals(3000000, $data['saldo_awal']);
        $this->assertEquals(3000000 + 3000000 - 300000 - 50000, $data['saldo_akhir']);
    }

    // ── Laporan pengeluaran ─────────────────────────────────────────────────

    public function test_laporan_pengeluaran_mencatat_pengajuan_kas_kecil_sekali(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->saldoAwalKasKecil(1000000);
        $id = $this->pengajuanSiapBayar(['nominal' => 300000]);
        $this->bayar($id)->assertStatus(200);

        $rows = collect($this->getJson('/api/laporan-pengeluaran?dari=2026-08-01&sampai=2026-08-31&limit=100')
            ->assertStatus(200)->json('data.data'));

        $this->assertCount(1, $rows);
        $this->assertSame('kas_kecil', $rows[0]['sumber_dana']);
        $this->assertSame('legalitas', $rows[0]['kategori']);
    }
}
