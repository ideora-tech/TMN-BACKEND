<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Exports\SheetTabelTautan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LaporanPemasukanTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\MemeriksaExcel;

    private function buatFaktur(string $nomor, string $tanggal, float $total, array $override = []): string
    {
        $this->ensurePerusahaan();
        $idPerusahaan = $override['id_perusahaan'] ?? self::PERUSAHAAN_ID;
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => $idPerusahaan, 'kode_klien' => 'KLN-' . Str::random(8),
            'nama_klien' => 'PT Klien Setia', 'dibuat_pada' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('faktur')->insert(array_merge([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien, 'nomor_faktur' => $nomor,
            'total' => $total, 'status' => 'terkirim', 'tanggal_faktur' => $tanggal, 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function buatPembayaran(string $idFaktur, string $tanggal, float $nominal, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pembayaran_faktur')->insert(array_merge([
            'id_pembayaran_faktur' => $id, 'id_faktur' => $idFaktur, 'tanggal_bayar' => $tanggal,
            'nominal' => $nominal, 'potongan' => 0, 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function buatPemasukan(string $nomor, string $tanggal, float $nominal, array $override = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('pemasukan')->insert(array_merge([
            'id_pemasukan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pemasukan' => $nomor,
            'kategori' => 'lainnya', 'tanggal' => $tanggal, 'nominal' => $nominal, 'sumber_dana' => 'Bank Mandiri',
            'keterangan' => 'Bunga jasa giro', 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function siapkanData(): array
    {
        $this->ensurePerusahaan();

        $fakturA = $this->buatFaktur('INV-202608-0001', '2026-08-20', 11100000);
        $fakturB = $this->buatFaktur('INV-202609-0002', '2026-09-03', 5000000);
        $fakturBatal = $this->buatFaktur('INV-202609-0003', '2026-09-10', 9000000, ['status' => 'batal']);
        $fakturHapus = $this->buatFaktur('INV-202609-0004', '2026-09-14', 7000000, ['dihapus_pada' => now()]);

        $this->buatPembayaran($fakturA, '2026-09-05', 6000000, ['no_referensi' => 'TRF-001', 'url_bukti' => 'bukti-faktur/a.pdf', 'catatan' => 'Termin 1']);
        $this->buatPembayaran($fakturA, '2026-09-25', 4900000, ['potongan' => 200000, 'keterangan_potongan' => 'PPh 23']);
        $this->buatPembayaran($fakturA, '2026-08-25', 1000000);
        $this->buatPembayaran($fakturB, '2026-09-12', 2000000, ['dihapus_pada' => now()]);
        $this->buatPembayaran($fakturBatal, '2026-09-11', 3000000);
        $this->buatPembayaran($fakturHapus, '2026-09-16', 7000000);

        $this->buatPemasukan('PM-202609-0001', '2026-09-08', 750000, ['kategori' => 'penjualan_aset', 'sumber_dana' => 'CV Besi Tua', 'keterangan' => 'Jual ban bekas']);
        $idPelunasan = $this->buatPemasukan('PM-202609-0002', '2026-09-15', 500000, ['kategori' => 'pengembalian_dana', 'sumber_dana' => 'Rudi Hartono', 'keterangan' => 'Pelunasan kasbon KSB-202608-0001']);
        $this->buatPemasukan('PM-202609-0003', '2026-09-18', 300000, ['dihapus_pada' => now()]);
        $this->buatPemasukan('PM-202610-0001', '2026-10-01', 999000);

        $idKasbon = (string) Str::uuid();
        DB::table('kasbon_pembayaran')->insert([
            'id_kasbon_pembayaran' => (string) Str::uuid(), 'id_kasbon' => $idKasbon, 'tanggal' => '2026-09-15',
            'nominal' => 500000, 'sumber' => 'manual', 'id_pemasukan' => $idPelunasan, 'dibuat_pada' => now(),
        ]);
        DB::table('kasbon_pembayaran')->insert([
            'id_kasbon_pembayaran' => (string) Str::uuid(), 'id_kasbon' => '00000000-0000-4000-8000-000000000001', 'tanggal' => '2026-09-15',
            'nominal' => 500000, 'sumber' => 'manual', 'id_pemasukan' => $idPelunasan, 'dibuat_pada' => now(), 'dihapus_pada' => now(),
        ]);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $fakturLain = $this->buatFaktur('INV-LAIN-0001', '2026-09-02', 80000000, ['id_perusahaan' => $idLain]);
        $this->buatPembayaran($fakturLain, '2026-09-09', 80000000);
        $this->buatPemasukan('PM-LAIN-0001', '2026-09-09', 70000000, ['id_perusahaan' => $idLain]);

        return ['faktur' => $fakturA, 'faktur_b' => $fakturB, 'kasbon' => $idKasbon];
    }

    private function url(string $tambahan = ''): string
    {
        return '/api/laporan-pemasukan?dari=2026-09-01&sampai=2026-09-30' . $tambahan;
    }

    public function test_laporan_menghitung_uang_yang_benar_benar_diterima(): void
    {
        $id = $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $res = $this->getJson($this->url('&limit=50'))->assertStatus(200);

        $res->assertJsonPath('data.ringkasan.total_pemasukan', 12150000)
            ->assertJsonPath('data.ringkasan.dari_invoice', 10900000)
            ->assertJsonPath('data.ringkasan.pemasukan_lain', 1250000)
            ->assertJsonPath('data.ringkasan.potongan_klien', 200000)
            ->assertJsonPath('data.ringkasan.jumlah_transaksi', 4)
            ->assertJsonPath('data.ringkasan.invoice_terbit.jumlah', 1)
            ->assertJsonPath('data.ringkasan.invoice_terbit.total', 5000000)
            ->assertJsonPath('data.rincian.jumlah', 4)
            ->assertJsonPath('data.rincian.total', 12150000)
            ->assertJsonPath('data.meta.total', 4)
            ->assertJsonPath('data.per_kategori.0.kategori', 'pembayaran_invoice')
            ->assertJsonPath('data.per_kategori.0.label', 'Pembayaran Invoice Klien')
            ->assertJsonPath('data.per_kategori.0.total', 10900000)
            ->assertJsonCount(3, 'data.per_kategori');

        $baris = collect($res->json('data.data'));
        $this->assertSame(['2026-09-25', '2026-09-15', '2026-09-08', '2026-09-05'], $baris->pluck('tanggal')->all());

        $termin = $baris->firstWhere('tanggal', '2026-09-05');
        $this->assertSame('INV-202608-0001', $termin['nomor']);
        $this->assertSame('PT Klien Setia', $termin['pihak']);
        $this->assertSame('TRF-001', $termin['no_referensi']);
        $this->assertSame('/faktur/' . $id['faktur'], $termin['link_dokumen']);
        $this->assertSame('Invoice', $termin['label_dokumen']);
        $this->assertStringEndsWith('/storage/bukti-faktur/a.pdf', $termin['url_bukti']);

        $berpotongan = $baris->firstWhere('tanggal', '2026-09-25');
        $this->assertEquals(4900000, $berpotongan['nominal']);
        $this->assertEquals(200000, $berpotongan['potongan']);
        $this->assertSame('PPh 23', $berpotongan['keterangan_potongan']);

        $pelunasan = $baris->firstWhere('nomor', 'PM-202609-0002');
        $this->assertSame('Pengembalian Dana', $pelunasan['label_kategori']);
        $this->assertSame('/kasbon/' . $id['kasbon'], $pelunasan['link_dokumen']);
        $this->assertSame('Kasbon', $pelunasan['label_dokumen']);

        $manual = $baris->firstWhere('nomor', 'PM-202609-0001');
        $this->assertSame('/arus-kas?tab=pemasukan', $manual['link_dokumen']);
        $this->assertSame('CV Besi Tua', $manual['pihak']);
        $this->assertNull($manual['url_bukti']);
    }

    public function test_pemasukan_lain_sama_dengan_pemasukan_manual_arus_kas_dan_invoice_terbit_sama_dengan_dasar_arus_kas(): void
    {
        $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $laporan = $this->getJson($this->url())->assertStatus(200)->json('data.ringkasan');
        $arusKas = collect($this->getJson('/api/arus-kas?dari=2026-09-01&sampai=2026-09-30')->assertStatus(200)->json('data.transaksi'));

        $this->assertEquals($arusKas->where('sumber', 'pemasukan_manual')->sum('nominal'), $laporan['pemasukan_lain']);
        $this->assertEquals($arusKas->where('sumber', 'faktur')->sum('nominal'), $laporan['invoice_terbit']['total']);
    }

    public function test_filter_kategori_pencarian_halaman_dan_validasi(): void
    {
        $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $this->getJson($this->url('&kategori=pembayaran_invoice'))
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.rincian.total', 10900000)
            ->assertJsonPath('data.rincian.potongan', 200000)
            ->assertJsonPath('data.ringkasan.total_pemasukan', 12150000)
            ->assertJsonCount(3, 'data.per_kategori');
        $this->getJson($this->url('&kategori=penjualan_aset'))->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.nomor', 'PM-202609-0001');
        $this->getJson($this->url('&search=besi+tua'))->assertJsonPath('data.meta.total', 1);
        $this->getJson($this->url('&search=TRF-001'))->assertJsonPath('data.meta.total', 1);
        $this->getJson($this->url('&search=klien+setia'))->assertJsonPath('data.meta.total', 2);
        $this->getJson($this->url('&search=%25'))->assertJsonPath('data.meta.total', 0);

        $this->getJson($this->url('&limit=3&page=2'))
            ->assertJsonPath('data.meta.totalPages', 2)
            ->assertJsonCount(1, 'data.data');

        $this->getJson('/api/laporan-pemasukan?dari=2026-09-30&sampai=2026-09-01')->assertStatus(422);
        $this->getJson('/api/laporan-pemasukan?dari=2025-01-01&sampai=2026-09-01')->assertStatus(422);
        $this->getJson('/api/laporan-pemasukan?dari=kemarin')->assertStatus(422);
        $this->getJson($this->url('&limit=500'))->assertStatus(422);
        $this->assertSame('pembayaran_invoice', $this->getJson($this->url())->json('data.opsi_kategori.0.value'));
    }

    public function test_izin_laporan_pemasukan(): void
    {
        $this->actingAsRole('MANAGER');
        $this->getJson($this->url())->assertStatus(200);

        $this->actingAsRole('SALES');
        $this->getJson($this->url())->assertStatus(403);
        $this->get('/api/laporan-pemasukan/export/excel?dari=2026-09-01&sampai=2026-09-30', ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_export_excel_berisi_ringkasan_rincian_dan_tautan(): void
    {
        $id = $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $unduh = $this->get('/api/laporan-pemasukan/export/excel?dari=2026-09-01&sampai=2026-09-30', ['X-Frontend-Url' => 'https://app.contoh.test']);
        $unduh->assertStatus(200);
        $this->assertStringContainsString('laporan-pemasukan-', (string) $unduh->headers->get('content-disposition'));

        $berkas   = $unduh->baseResponse->getFile()->getPathname();
        $workbook = IOFactory::load($berkas);
        $this->assertSame(['Ringkasan', 'Rincian'], $workbook->getSheetNames());
        $this->assertTrue($this->selExcelBerupaTeks($berkas, 2, 'C4'));
        $this->assertFalse($this->selExcelBerupaTeks($berkas, 2, 'G4'));
        $this->assertFalse($this->selExcelBerupaTeks($berkas, 1, 'C4'));

        $ringkasan = $workbook->getSheetByName('Ringkasan');
        $this->assertSame('Total pemasukan (uang diterima)', $ringkasan->getCell('A4')->getValue());
        $this->assertEquals(12150000, $ringkasan->getCell('C4')->getValue());
        $this->assertEquals(200000, $ringkasan->getCell('C7')->getValue());
        $this->assertSame('Invoice tercatat pada periode ini (dasar Arus Kas, termasuk yang masih draft)', $ringkasan->getCell('A8')->getValue());
        $this->assertEquals(5000000, $ringkasan->getCell('C8')->getValue());

        $rincian = $workbook->getSheetByName('Rincian');
        $this->assertSame('Periode 01/09/2026 s/d 30/09/2026', $rincian->getCell('A2')->getValue());
        $this->assertSame('Diterima Dari', $rincian->getCell('E3')->getValue());
        $this->assertSame('INV-202608-0001', $rincian->getCell('C4')->getValue());
        $this->assertSame('TRF-001 — Termin 1', $rincian->getCell('F4')->getValue());
        $this->assertEquals(6000000, $rincian->getCell('G4')->getValue());
        $this->assertSame('https://app.contoh.test/faktur/' . $id['faktur'], $rincian->getCell('J4')->getHyperlink()->getUrl());
        $this->assertStringEndsWith('/storage/bukti-faktur/a.pdf', $rincian->getCell('K4')->getHyperlink()->getUrl());
        $this->assertSame('https://app.contoh.test/kasbon/' . $id['kasbon'], $rincian->getCell('J6')->getHyperlink()->getUrl());
        $this->assertEquals(200000, $rincian->getCell('H7')->getValue());
        $this->assertSame('TOTAL PEMASUKAN', $rincian->getCell('F8')->getValue());
        $this->assertEquals(12150000, $rincian->getCell('G8')->getValue());

        $tersaring = $this->get('/api/laporan-pemasukan/export/excel?dari=2026-09-01&sampai=2026-09-30&kategori=penjualan_aset&search=besi');
        $tersaring->assertStatus(200);
        $buku = IOFactory::load($tersaring->baseResponse->getFile()->getPathname());
        $this->assertSame('Periode 01/09/2026 s/d 30/09/2026', $buku->getSheetByName('Ringkasan')->getCell('A2')->getValue());
        $this->assertEquals(12150000, $buku->getSheetByName('Ringkasan')->getCell('C4')->getValue());
        $this->assertSame(
            'Periode 01/09/2026 s/d 30/09/2026 · Kategori: Penjualan Aset · Pencarian: "besi"',
            $buku->getSheetByName('Rincian')->getCell('A2')->getValue(),
        );
        $this->assertSame('TOTAL PEMASUKAN', $buku->getSheetByName('Rincian')->getCell('F5')->getValue());
        $this->assertEquals(750000, $buku->getSheetByName('Rincian')->getCell('G5')->getValue());
    }

    public function test_excel_arus_kas_punya_kolom_dokumen_dan_bukti_bertautan(): void
    {
        $id = $this->siapkanData();
        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idPengajuan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pengajuan' => 'PP-202609-0001',
            'kategori' => 'legalitas', 'nominal' => 1500000, 'tanggal_pengajuan' => '2026-09-10', 'penerima' => 'Samsat',
            'keterangan' => 'Perpanjangan STNK', 'status' => 'ditransfer', 'tanggal_transfer' => '2026-09-11',
            'url_bukti' => 'bukti-kas/stnk.pdf', 'dibuat_pada' => now(),
        ]);
        $this->actingAsRole('KEUANGAN');

        $unduh = $this->get('/api/arus-kas/export/excel?dari=2026-09-01&sampai=2026-09-30', ['X-Frontend-Url' => 'https://app.contoh.test']);
        $unduh->assertStatus(200);

        $berkas   = $unduh->baseResponse->getFile()->getPathname();
        $workbook = IOFactory::load($berkas);
        $this->assertSame(['Laporan Arus Kas', 'Rincian Transaksi'], $workbook->getSheetNames());
        $this->assertFalse($this->selExcelBerupaTeks($berkas, 2, 'A4'));
        $this->assertTrue($this->selExcelBerupaTeks($berkas, 2, 'E4'));

        $sheet = $workbook->getSheetByName('Rincian Transaksi');
        $this->assertSame('REKAP ARUS KAS', $sheet->getCell('A1')->getValue());
        $kepala = [];
        foreach (range('A', 'J') as $kolom) {
            $kepala[] = $sheet->getCell($kolom . '3')->getValue();
        }
        $this->assertSame(['No', 'Tanggal', 'Sumber', 'Kategori', 'Referensi', 'Keterangan', 'Masuk', 'Keluar', 'Dokumen', 'Bukti'], $kepala);

        $baris = [];
        for ($i = 4; $i <= $sheet->getHighestRow(); $i++) {
            $baris[(string) $sheet->getCell("E{$i}")->getValue()] = $i;
        }

        $keluar = $baris['PP-202609-0001'];
        $this->assertSame('Legalitas', $sheet->getCell("D{$keluar}")->getValue());
        $this->assertEquals(1500000, $sheet->getCell("H{$keluar}")->getValue());
        $this->assertSame('https://app.contoh.test/proses-pembayaran?tab=ditransfer', $sheet->getCell("I{$keluar}")->getHyperlink()->getUrl());
        $this->assertStringEndsWith('/storage/bukti-kas/stnk.pdf', $sheet->getCell("J{$keluar}")->getHyperlink()->getUrl());

        $invoice = $baris['INV-202609-0002'];
        $this->assertSame('Invoice Klien', $sheet->getCell("D{$invoice}")->getValue());
        $this->assertEquals(5000000, $sheet->getCell("G{$invoice}")->getValue());
        $this->assertSame('https://app.contoh.test/faktur/' . $id['faktur_b'], $sheet->getCell("I{$invoice}")->getHyperlink()->getUrl());

        $manual = $baris['PM-202609-0001'];
        $this->assertSame('Penjualan Aset', $sheet->getCell("D{$manual}")->getValue());
        $this->assertSame('https://app.contoh.test/arus-kas?tab=pemasukan', $sheet->getCell("I{$manual}")->getHyperlink()->getUrl());

        $akhir = $sheet->getHighestRow();
        $this->assertSame('NETTO', $sheet->getCell("F{$akhir}")->getValue());
        $this->assertEquals(6250000 - 1500000, $sheet->getCell("G{$akhir}")->getValue());
        $this->assertSame('TOTAL PEMASUKAN', $sheet->getCell('F' . ($akhir - 2))->getValue());
        $this->assertEquals(6250000, $sheet->getCell('G' . ($akhir - 2))->getValue());
        $this->assertEquals(1500000, $sheet->getCell('H' . ($akhir - 1))->getValue());
    }

    public function test_excel_arus_kas_menolak_data_melebihi_batas_baris(): void
    {
        $this->ensurePerusahaan();
        $baris = [];
        for ($i = 0; $i <= SheetTabelTautan::BATAS_BARIS; $i++) {
            $baris[] = [
                'id_pemasukan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pemasukan' => 'PM-MASSAL-' . $i,
                'kategori' => 'lainnya', 'tanggal' => '2026-09-08', 'nominal' => 1000, 'sumber_dana' => 'Bank Mandiri',
                'keterangan' => 'Massal', 'dibuat_pada' => now(),
            ];
        }
        foreach (array_chunk($baris, 100) as $potongan) {
            DB::table('pemasukan')->insert($potongan);
        }
        $this->actingAsRole('KEUANGAN');

        $tolak = $this->get('/api/arus-kas/export/excel?dari=2026-09-01&sampai=2026-09-30', ['Accept' => 'application/json']);
        $tolak->assertStatus(422);
        $this->assertStringContainsString('persempit periode', (string) $tolak->json('message'));

        $this->get('/api/arus-kas/export/excel?dari=2026-09-01&sampai=2026-09-30&arah=keluar', ['Accept' => 'application/json'])
            ->assertStatus(200);
    }
}
