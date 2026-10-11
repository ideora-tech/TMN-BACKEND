<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\LaporanPengeluaran\Exports\LaporanPengeluaranExport;
use App\Modules\LaporanPengeluaran\LaporanPengeluaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LaporanPengeluaranTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\MemeriksaExcel;

    private const TANGGAL = '2026-09-15';

    private function buatPengajuan(array $override = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert(array_merge([
            'id_pengajuan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-202609-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'kategori' => 'legalitas', 'nominal' => 1000000, 'tanggal_pengajuan' => '2026-09-10',
            'penerima' => 'Samsat Jakarta', 'keterangan' => 'Perpanjangan STNK', 'status' => 'ditransfer',
            'tanggal_transfer' => self::TANGGAL, 'url_bukti' => 'bukti-kas/bukti.pdf', 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function buatPembayaranVendor(float $nominal = 4000000, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendor, 'id_perusahaan' => $idPerusahaan, 'kode_vendor' => 'VDR-' . Str::random(6),
            'nama_vendor' => 'PT Vendor Angkut', 'dibuat_pada' => now(),
        ]);
        $idInvoice = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $idInvoice, 'id_perusahaan' => $idPerusahaan, 'id_vendor' => $idVendor,
            'nomor_invoice' => 'IV-2026-0009', 'tanggal_invoice' => '2026-09-01', 'dpp' => $nominal, 'ppn' => 0, 'pph' => 0,
            'total' => $nominal, 'status' => 'diverifikasi', 'status_pembayaran' => 'lunas', 'dibuat_pada' => now(),
        ]);
        DB::table('pembayaran_vendor')->insert([
            'id_pembayaran_vendor' => (string) Str::uuid(), 'id_invoice_vendor' => $idInvoice, 'tanggal_bayar' => self::TANGGAL,
            'nominal' => $nominal, 'metode' => 'transfer', 'url_bukti' => 'bukti-kas/vendor.pdf', 'catatan' => 'Pelunasan sewa',
            'dibuat_pada' => now(),
        ]);

        return $idInvoice;
    }

    private function buatKasKecil(array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('kas_kecil_transaksi')->insert(array_merge([
            'id_transaksi' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_transaksi' => 'KK-202609-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'tanggal' => self::TANGGAL, 'arah' => 'keluar', 'jenis' => 'pengeluaran', 'kategori' => 'atk',
            'nominal' => 150000, 'penerima' => 'Toko ATK', 'keterangan' => 'Kertas HVS', 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function siapkanData(): array
    {
        $this->ensurePerusahaan();
        $idKasbon      = (string) Str::uuid();
        $idPengisian   = (string) Str::uuid();
        $idInvoice     = $this->buatPembayaranVendor(4000000);

        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0001']);
        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0002', 'kategori' => 'kasbon', 'nominal' => 2000000, 'penerima' => 'Rudi Hartono', 'id_kasbon' => $idKasbon]);
        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0003', 'kategori' => 'pengisian_kas_kecil', 'nominal' => 500000, 'penerima' => 'Kas Kecil', 'id_pengisian_kas_kecil' => $idPengisian]);
        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0004', 'kategori' => 'pembayaran_vendor', 'nominal' => 4000000, 'id_invoice_vendor' => $idInvoice]);
        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0005', 'status' => 'siap_transfer', 'tanggal_transfer' => null]);
        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0006', 'tanggal_transfer' => '2026-08-31']);
        $this->buatKasKecil(['nomor_transaksi' => 'KK-202609-0002']);
        $this->buatKasKecil(['nomor_transaksi' => 'KK-202609-0001', 'arah' => 'masuk', 'jenis' => 'pengisian', 'kategori' => null, 'nominal' => 500000]);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->buatPengajuan(['id_perusahaan' => $idLain, 'nomor_pengajuan' => 'PP-LAIN-0001', 'nominal' => 99000000]);
        $this->buatKasKecil(['id_perusahaan' => $idLain, 'nomor_transaksi' => 'KK-LAIN-0001', 'nominal' => 77000000]);
        $this->buatPembayaranVendor(55000000, $idLain);

        return ['id_kasbon' => $idKasbon, 'id_pengisian' => $idPengisian, 'id_invoice' => $idInvoice];
    }

    private function url(string $tambahan = ''): string
    {
        return '/api/laporan-pengeluaran?dari=2026-09-01&sampai=2026-09-30' . $tambahan;
    }

    public function test_laporan_menggabungkan_rekening_pembayaran_vendor_dan_kas_kecil_tanpa_hitung_ganda(): void
    {
        $id = $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $res = $this->getJson($this->url('&limit=50'))->assertStatus(200);

        $res->assertJsonPath('data.ringkasan.total_pengeluaran', 7150000)
            ->assertJsonPath('data.ringkasan.dari_rekening', 7000000)
            ->assertJsonPath('data.ringkasan.dari_kas_kecil', 150000)
            ->assertJsonPath('data.ringkasan.mutasi_kas_kecil', 500000)
            ->assertJsonPath('data.ringkasan.jumlah_transaksi', 4)
            ->assertJsonPath('data.rincian.jumlah', 5)
            ->assertJsonPath('data.rincian.total', 7150000)
            ->assertJsonPath('data.rincian.mutasi', 500000)
            ->assertJsonPath('data.meta.total', 5);

        $baris = collect($res->json('data.data'))->keyBy('nomor');
        $this->assertEqualsCanonicalizing(
            ['PP-202609-0001', 'PP-202609-0002', 'PP-202609-0003', 'IV-2026-0009', 'KK-202609-0002'],
            $baris->keys()->all(),
        );

        $this->assertSame('/kasbon/' . $id['id_kasbon'], $baris['PP-202609-0002']['link_dokumen']);
        $this->assertSame('Kasbon', $baris['PP-202609-0002']['label_dokumen']);
        $this->assertSame('/kas-kecil/pengisian/' . $id['id_pengisian'], $baris['PP-202609-0003']['link_dokumen']);
        $this->assertTrue($baris['PP-202609-0003']['mutasi']);
        $this->assertSame('/invoice-vendor/' . $id['id_invoice'], $baris['IV-2026-0009']['link_dokumen']);
        $this->assertSame('PT Vendor Angkut', $baris['IV-2026-0009']['penerima']);
        $this->assertSame('Pembayaran Vendor', $baris['IV-2026-0009']['label_kategori']);
        $this->assertSame('/proses-pembayaran?tab=ditransfer', $baris['PP-202609-0001']['link_dokumen']);
        $this->assertSame('Kas Kecil — ATK & Perlengkapan Kantor', $baris['KK-202609-0002']['label_kategori']);
        $this->assertSame('kas_kecil', $baris['KK-202609-0002']['sumber_dana']);
        $this->assertSame('/kas-kecil?tab=buku&tanggal=2026-09-15&cari=KK-202609-0002', $baris['KK-202609-0002']['link_dokumen']);
        $this->assertStringEndsWith('/storage/bukti-kas/bukti.pdf', $baris['PP-202609-0001']['url_bukti']);
        $this->assertNull($baris['KK-202609-0002']['url_bukti']);

        $perKategori = collect($res->json('data.per_kategori'));
        $this->assertSame('pembayaran_vendor', $perKategori[0]['kategori']);
        $this->assertTrue($perKategori->last()['mutasi']);
        $this->assertEquals(7150000, $perKategori->where('mutasi', false)->sum('total'));
        $this->assertSame('kas_kecil:atk', $perKategori->firstWhere('kategori', 'kas_kecil')['nilai_filter']);
        $this->assertSame('kasbon', $perKategori->firstWhere('kategori', 'kasbon')['nilai_filter']);
    }

    public function test_tautan_perawatan_membawa_armada_agar_detailnya_terbuka(): void
    {
        $this->ensurePerusahaan();
        $idArmada    = (string) Str::uuid();
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $idArmada, 'tanggal' => '2026-09-12', 'biaya' => 100000,
            'status' => 'selesai', 'dibuat_pada' => now(),
        ]);
        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0011', 'kategori' => 'perawatan', 'id_perawatan' => $idPerawatan]);
        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0012', 'kategori' => 'perawatan', 'id_perawatan' => (string) Str::uuid()]);
        $this->actingAsRole('KEUANGAN');

        $baris = collect($this->getJson($this->url())->assertStatus(200)->json('data.data'))->keyBy('nomor');

        $this->assertSame("/perawatan-armada?armada={$idArmada}&detail={$idPerawatan}", $baris['PP-202609-0011']['link_dokumen']);
        $this->assertSame('/perawatan-armada', $baris['PP-202609-0012']['link_dokumen']);
        $this->assertSame('Perawatan Armada', $baris['PP-202609-0011']['label_dokumen']);
    }

    public function test_filter_sumber_dana_kategori_pencarian_dan_halaman(): void
    {
        $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $this->getJson($this->url('&sumber_dana=kas_kecil'))
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.ringkasan.total_pengeluaran', 150000)
            ->assertJsonPath('data.ringkasan.dari_rekening', 0);
        $this->getJson($this->url('&kategori=kasbon'))
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.nomor', 'PP-202609-0002')
            ->assertJsonPath('data.rincian.total', 2000000)
            ->assertJsonPath('data.ringkasan.total_pengeluaran', 7150000)
            ->assertJsonCount(5, 'data.per_kategori');
        $this->getJson($this->url('&kategori=pengisian_kas_kecil'))
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.rincian.total', 0)
            ->assertJsonPath('data.rincian.mutasi', 500000);
        $this->getJson($this->url('&kategori=kas_kecil'))->assertJsonPath('data.meta.total', 1);
        $this->getJson($this->url('&kategori=kas_kecil:atk'))->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.rincian.total', 150000);
        $this->getJson($this->url('&kategori=kas_kecil:bbm'))->assertJsonPath('data.meta.total', 0);
        $this->getJson($this->url('&search=vendor+angkut'))
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.nomor', 'IV-2026-0009');

        $halaman2 = $this->getJson($this->url('&limit=2&page=2'))->assertStatus(200);
        $halaman2->assertJsonPath('data.meta.total', 5)->assertJsonPath('data.meta.totalPages', 3)->assertJsonCount(2, 'data.data');

        $this->getJson('/api/laporan-pengeluaran?dari=2026-09-30&sampai=2026-09-01')->assertStatus(422);
        $this->getJson('/api/laporan-pengeluaran?dari=2025-01-01&sampai=2026-09-01')->assertStatus(422);
        $this->getJson($this->url('&sumber_dana=dompet'))->assertStatus(422);
        $this->assertNotEmpty($this->getJson($this->url())->json('data.opsi_kategori'));
    }

    public function test_izin_laporan_pengeluaran(): void
    {
        $this->actingAsRole('MANAGER');
        $this->getJson($this->url())->assertStatus(200);

        $this->actingAsRole('SALES');
        $this->getJson($this->url())->assertStatus(403);
        $this->get('/api/laporan-pengeluaran/export/excel?dari=2026-09-01&sampai=2026-09-30', ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_export_excel_berisi_tautan_dokumen_dan_bukti(): void
    {
        $id = $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $unduh = $this->get('/api/laporan-pengeluaran/export/excel?dari=2026-09-01&sampai=2026-09-30', ['X-Frontend-Url' => 'https://app.contoh.test']);
        $unduh->assertStatus(200);
        $this->assertStringContainsString('laporan-pengeluaran-', (string) $unduh->headers->get('content-disposition'));

        $laporan = app(LaporanPengeluaranService::class)->untukExport(self::PERUSAHAAN_ID, ['dari' => '2026-09-01', 'sampai' => '2026-09-30']);
        $berkas  = tempnam(sys_get_temp_dir(), 'lap') . '.xlsx';
        file_put_contents($berkas, Excel::raw(new LaporanPengeluaranExport($laporan, 'TMN Transport Test', 'https://app.contoh.test'), \Maatwebsite\Excel\Excel::XLSX));

        try {
            $workbook = IOFactory::load($berkas);
            $this->assertSame(['Ringkasan', 'Rincian'], $workbook->getSheetNames());

            $sheet = $workbook->getSheetByName('Rincian');
            $this->assertSame('RINCIAN PENGELUARAN — TMN TRANSPORT TEST', $sheet->getCell('A1')->getValue());
            $this->assertSame('Dokumen', $sheet->getCell('J3')->getValue());

            $tautan = [];
            for ($baris = 4; $baris <= 8; $baris++) {
                $tautan[(string) $sheet->getCell("C{$baris}")->getValue()] = [
                    'dokumen' => $sheet->getCell("J{$baris}")->getHyperlink()->getUrl(),
                    'bukti'   => $sheet->getCell("K{$baris}")->getHyperlink()->getUrl(),
                ];
            }

            $this->assertSame('https://app.contoh.test/kasbon/' . $id['id_kasbon'], $tautan['PP-202609-0002']['dokumen']);
            $this->assertSame('https://app.contoh.test/invoice-vendor/' . $id['id_invoice'], $tautan['IV-2026-0009']['dokumen']);
            $this->assertStringEndsWith('/storage/bukti-kas/vendor.pdf', $tautan['IV-2026-0009']['bukti']);
            $this->assertSame('', $tautan['KK-202609-0002']['bukti']);
            $this->assertSame('TOTAL PENGELUARAN', $sheet->getCell('G9')->getValue());
            $this->assertEquals(7150000, $sheet->getCell('H9')->getValue());

            $tanpaAsal = tempnam(sys_get_temp_dir(), 'lap') . '.xlsx';
            file_put_contents($tanpaAsal, Excel::raw(new LaporanPengeluaranExport($laporan, '', null), \Maatwebsite\Excel\Excel::XLSX));
            $polos = IOFactory::load($tanpaAsal)->getSheetByName('Rincian');
            $this->assertSame('', $polos->getCell('J4')->getHyperlink()->getUrl());
            $this->assertNotSame('', (string) $polos->getCell('J4')->getValue());
            @unlink($tanpaAsal);
        } finally {
            @unlink($berkas);
        }
    }

    public function test_asal_frontend_yang_tidak_sah_diabaikan(): void
    {
        $request = fn (string $nilai) => \Illuminate\Http\Request::create('/x', 'GET', [], [], [], ['HTTP_X_FRONTEND_URL' => $nilai]);

        $this->assertSame('https://app.contoh.test', \App\Support\AsalFrontend::dari($request('https://app.contoh.test/')));
        $this->assertSame('http://localhost:3019', \App\Support\AsalFrontend::dari($request('http://localhost:3019')));
        $this->assertNull(\App\Support\AsalFrontend::dari($request('javascript:alert(1)')));
        $this->assertNull(\App\Support\AsalFrontend::dari($request('https://jahat.test/path?x=1')));
        $this->assertNull(\App\Support\AsalFrontend::tautan('https://app.contoh.test', 'https://jahat.test'));

        config(['app.frontend_url' => 'https://resmi.contoh.test']);
        $this->assertSame('https://resmi.contoh.test', \App\Support\AsalFrontend::dari($request('https://lain.contoh.test')));
    }

    public function test_total_sisi_rekening_sama_dengan_pengeluaran_arus_kas(): void
    {
        $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $laporan = $this->getJson($this->url())->assertStatus(200)->json('data.ringkasan');
        $arusKas = $this->getJson('/api/arus-kas?dari=2026-09-01&sampai=2026-09-30')->assertStatus(200)->json('data.ringkasan');

        $this->assertEquals($arusKas['total_pengeluaran'], $laporan['dari_rekening'] + $laporan['mutasi_kas_kecil']);
        $this->assertEquals(7500000, $arusKas['total_pengeluaran']);
    }

    public function test_baris_terhapus_tidak_ikut_dan_wildcard_pencarian_dibaca_harfiah(): void
    {
        $this->siapkanData();
        $this->buatPengajuan(['nomor_pengajuan' => 'PP-202609-0091', 'nominal' => 9000000, 'dihapus_pada' => now()]);
        $this->buatKasKecil(['nomor_transaksi' => 'KK-202609-0091', 'nominal' => 8000000, 'dihapus_pada' => now()]);
        $this->buatKasKecil(['nomor_transaksi' => 'KK-202609-0092', 'nominal' => 25000, 'keterangan' => 'Diskon 50% parkir_bulanan']);
        $this->actingAsRole('KEUANGAN');

        $this->getJson($this->url('&limit=50'))
            ->assertJsonPath('data.ringkasan.total_pengeluaran', 7175000)
            ->assertJsonPath('data.meta.total', 6);

        $this->getJson($this->url('&search=%25'))->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.nomor', 'KK-202609-0092');
        $this->getJson($this->url('&search=parkir_bulanan'))->assertJsonPath('data.meta.total', 1);
        $this->getJson($this->url('&search=_'))->assertJsonPath('data.meta.total', 1);
    }

    public function test_dua_termin_vendor_di_tanggal_sama_tidak_tertukar_antar_halaman(): void
    {
        $this->ensurePerusahaan();
        $idInvoice = $this->buatPembayaranVendor(1000000);
        DB::table('pembayaran_vendor')->insert([
            'id_pembayaran_vendor' => (string) Str::uuid(), 'id_invoice_vendor' => $idInvoice, 'tanggal_bayar' => self::TANGGAL,
            'nominal' => 2500000, 'metode' => 'transfer', 'dibuat_pada' => now(),
        ]);
        $this->actingAsRole('KEUANGAN');

        $nominal = [];
        foreach ([1, 2] as $halaman) {
            $res = $this->getJson($this->url("&limit=1&page={$halaman}"))->assertStatus(200)->assertJsonCount(1, 'data.data');
            $nominal[] = (float) $res->json('data.data.0.nominal');
        }

        sort($nominal);
        $this->assertSame([1000000.0, 2500000.0], $nominal);
    }

    public function test_file_excel_dari_endpoint_memakai_asal_frontend_dan_menjaga_teks_angka(): void
    {
        $id = $this->siapkanData();
        $this->buatPengajuan(['nomor_pengajuan' => '3171234567890001', 'nominal' => 0.5, 'penerima' => '=1+1', 'keterangan' => '12E3']);
        $this->actingAsRole('KEUANGAN');

        $unduh = $this->get('/api/laporan-pengeluaran/export/excel?dari=2026-09-01&sampai=2026-09-30&kategori=kasbon', ['X-Frontend-Url' => 'https://app.contoh.test']);
        $unduh->assertStatus(200);
        $bukuKasbon = IOFactory::load($unduh->baseResponse->getFile()->getPathname());
        $sheet = $bukuKasbon->getSheetByName('Rincian');
        $this->assertSame('Periode 01/09/2026 s/d 30/09/2026 · Kategori: Kasbon', $sheet->getCell('A2')->getValue());
        $this->assertSame('Periode 01/09/2026 s/d 30/09/2026', $bukuKasbon->getSheetByName('Ringkasan')->getCell('A2')->getValue());
        $this->assertSame('PP-202609-0002', $sheet->getCell('C4')->getValue());
        $this->assertSame('https://app.contoh.test/kasbon/' . $id['id_kasbon'], $sheet->getCell('J4')->getHyperlink()->getUrl());
        $this->assertEquals(2000000, $sheet->getCell('H5')->getValue());

        $semua = $this->get('/api/laporan-pengeluaran/export/excel?dari=2026-09-01&sampai=2026-09-30&search=3171234567890001');
        $semua->assertStatus(200);
        $berkasSemua = $semua->baseResponse->getFile()->getPathname();
        $rincian = IOFactory::load($berkasSemua)->getSheetByName('Rincian');
        $this->assertSame('Periode 01/09/2026 s/d 30/09/2026 · Pencarian: "3171234567890001"', $rincian->getCell('A2')->getValue());
        $this->assertSame('3171234567890001', $rincian->getCell('C4')->getValue());
        $this->assertSame('=1+1', $rincian->getCell('F4')->getValue());
        $this->assertSame('12E3', $rincian->getCell('G4')->getValue());
        $this->assertTrue($this->selExcelBerupaTeks($berkasSemua, 2, 'C4'));
        $this->assertTrue($this->selExcelBerupaTeks($berkasSemua, 2, 'F4'));
        $this->assertTrue($this->selExcelBerupaTeks($berkasSemua, 2, 'G4'));
        $this->assertFalse($this->selExcelBerupaTeks($berkasSemua, 2, 'A4'));
        $this->assertFalse($this->selExcelBerupaTeks($berkasSemua, 2, 'H4'));

        $kasKecil = $this->get('/api/laporan-pengeluaran/export/excel?dari=2026-09-01&sampai=2026-09-30&sumber_dana=kas_kecil&kategori=kas_kecil');
        $kasKecil->assertStatus(200);
        $bukuKasKecil = IOFactory::load($kasKecil->baseResponse->getFile()->getPathname());
        $this->assertSame('Periode 01/09/2026 s/d 30/09/2026 · Sumber dana: Kas Kecil', $bukuKasKecil->getSheetByName('Ringkasan')->getCell('A2')->getValue());
        $this->assertSame(
            'Periode 01/09/2026 s/d 30/09/2026 · Kategori: Kas Kecil — semua kategori · Sumber dana: Kas Kecil',
            $bukuKasKecil->getSheetByName('Rincian')->getCell('A2')->getValue(),
        );

        $kosong = $this->get('/api/laporan-pengeluaran/export/excel?dari=2025-01-01&sampai=2025-01-31');
        $kosong->assertStatus(200);
        $berkasKosong = $kosong->baseResponse->getFile()->getPathname();
        $ringkasan = IOFactory::load($berkasKosong)->getSheetByName('Ringkasan');
        $this->assertSame('Total pengeluaran', $ringkasan->getCell('A4')->getValue());
        $this->assertSame(0, (int) $ringkasan->getCell('C4')->getValue());
        $this->assertNotNull($ringkasan->getCell('C4')->getValue());
        $this->assertFalse($this->selExcelBerupaTeks($berkasKosong, 1, 'C4'));
    }

    public function test_export_menolak_data_yang_melebihi_batas_baris(): void
    {
        \App\Support\Exports\SheetTabelTautan::pastikanBisaDiexport(\App\Support\Exports\SheetTabelTautan::BATAS_BARIS);

        try {
            \App\Support\Exports\SheetTabelTautan::pastikanBisaDiexport(\App\Support\Exports\SheetTabelTautan::BATAS_BARIS + 1);
            $this->fail('Seharusnya ditolak');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('persempit periode', $e->getMessage());
        }
    }

    public function test_parameter_dibaca_dari_hasil_validasi_bukan_query_mentah(): void
    {
        $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $this->json('GET', '/api/laporan-pengeluaran?dari=bukan-tanggal&sampai=2026-09-30', ['dari' => '2026-09-01'])
            ->assertStatus(200)
            ->assertJsonPath('data.periode.dari', '2026-09-01');
        $this->getJson('/api/laporan-pengeluaran?dari=bukan-tanggal')->assertStatus(422);
        $this->getJson($this->url('&limit=1000'))->assertStatus(422);
    }
}
