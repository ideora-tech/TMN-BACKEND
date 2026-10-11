<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\RekapPajak\Exports\RekapPajakExport;
use App\Modules\RekapPajak\RekapPajakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RekapPajakTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\MemeriksaExcel;

    private function buatKlien(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => $idPerusahaan, 'kode_klien' => 'KLN-' . Str::random(8),
            'nama_klien' => 'PT Klien Pajak', 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function buatFaktur(string $nomor, string $tanggal, float $dpp, array $pajak, array $override = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('faktur')->insert(array_merge([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $this->buatKlien(),
            'nomor_faktur' => $nomor, 'total' => $dpp, 'status' => 'terkirim', 'tanggal_faktur' => $tanggal, 'dibuat_pada' => now(),
        ], $override));
        DB::table('faktur_item')->insert([
            'id_faktur_item' => (string) Str::uuid(), 'id_faktur' => $id, 'deskripsi' => 'Jasa angkutan',
            'qty' => 1, 'harga_satuan' => $dpp, 'subtotal' => $dpp, 'dibuat_pada' => now(),
        ]);
        foreach ($pajak as $urutan => [$nama, $persen]) {
            DB::table('faktur_pajak')->insert([
                'id_faktur_pajak' => (string) Str::uuid(), 'id_faktur' => $id, 'nama' => $nama, 'persen' => $persen,
                'urutan' => $urutan + 1, 'dibuat_pada' => now(),
            ]);
        }

        return $id;
    }

    private function buatInvoiceVendor(string $nomor, string $tanggal, float $dpp, float $ppn, float $pph, array $override = []): string
    {
        $this->ensurePerusahaan();
        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendor, 'id_perusahaan' => $override['id_perusahaan'] ?? self::PERUSAHAAN_ID,
            'kode_vendor' => 'VDR-' . Str::random(6), 'nama_vendor' => 'PT Vendor Pajak', 'npwp' => '01.234.567.8-901.000', 'dibuat_pada' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('invoice_vendor')->insert(array_merge([
            'id_invoice_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $idVendor,
            'nomor_invoice' => $nomor, 'tanggal_invoice' => $tanggal, 'dpp' => $dpp, 'ppn' => $ppn, 'pph' => $pph,
            'total' => $dpp + $ppn - $pph, 'status' => 'diverifikasi', 'status_pembayaran' => 'belum', 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function buatPermintaan(string $nomor, string $tanggal, float $total, float $ppn, float $ongkir, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('permintaan_pembelian')->insert(array_merge([
            'id_permintaan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_permintaan' => $nomor,
            'id_pengaju' => (string) Str::uuid(), 'tipe' => 'umum', 'tanggal_permintaan' => $tanggal,
            'judul' => 'Pembelian ATK', 'alasan' => 'Kebutuhan kantor', 'status' => 'selesai', 'total_estimasi' => $total,
            'total_aktual' => $total, 'ppn' => $ppn, 'ppn_persen' => 11, 'ongkir' => $ongkir, 'tanggal_pembelian' => $tanggal,
            'nama_toko' => 'Toko Maju', 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function buatSlip(string $tanggalSelesai, float $bruto, float $pph21, string $status = 'final'): string
    {
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nik' => 'NIK-' . Str::random(6),
            'nama_karyawan' => 'Budi Santoso', 'nik_ktp' => '3171000000000001', 'npwp' => '09.876.543.2-100.000',
            'status_ptkp' => 'K/1', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idPeriode = (string) Str::uuid();
        DB::table('payroll_periode')->insert([
            'id_periode' => $idPeriode, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => 'Payroll ' . $tanggalSelesai,
            'tanggal_mulai' => substr($tanggalSelesai, 0, 8) . '01', 'tanggal_selesai' => $tanggalSelesai,
            'status' => $status, 'dibuat_pada' => now(),
        ]);
        DB::table('payroll_slip')->insert([
            'id_slip' => (string) Str::uuid(), 'id_periode' => $idPeriode, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'total_bruto' => $bruto, 'pph21' => $pph21, 'gaji_bersih' => $bruto - $pph21,
            'dibuat_pada' => now(),
        ]);

        return $idPeriode;
    }

    private function buatPembayaranFaktur(string $idFaktur, string $tanggal, float $nominal, float $potongan, ?string $keterangan): void
    {
        DB::table('pembayaran_faktur')->insert([
            'id_pembayaran_faktur' => (string) Str::uuid(), 'id_faktur' => $idFaktur, 'tanggal_bayar' => $tanggal,
            'nominal' => $nominal, 'potongan' => $potongan, 'keterangan_potongan' => $keterangan, 'dibuat_pada' => now(),
        ]);
    }

    private function siapkanData(): array
    {
        $this->ensurePerusahaan();

        $fakturPpn = $this->buatFaktur('INV-202609-0001', '2026-09-05', 10000000, [['PPN 11%', 11]]);
        $this->buatFaktur('INV-202609-0002', '2026-09-20', 5000000, [['PPN', 11], ['Biaya Admin', 1]], ['status' => 'lunas']);
        $this->buatFaktur('INV-202609-0003', '2026-09-21', 3000000, []);
        $this->buatFaktur('INV-202609-0004', '2026-09-22', 8000000, [['PPN', 11]], ['status' => 'draft']);
        $this->buatFaktur('INV-202609-0005', '2026-09-23', 8000000, [['PPN', 11]], ['status' => 'batal']);
        $this->buatFaktur('INV-202609-0006', '2026-09-24', 2000000, [], ['nama_pajak' => null, 'persen_pajak' => 11]);
        $this->buatFaktur('INV-202610-0001', '2026-10-02', 4000000, [['PPN', 11]]);

        $invoiceVendor = $this->buatInvoiceVendor('IV-2026-0001', '2026-09-10', 20000000, 2200000, 400000);
        $this->buatInvoiceVendor('IV-2026-0002', '2026-09-11', 9000000, 990000, 180000, ['status' => 'draft']);
        $this->buatInvoiceVendor('IV-2026-0003', '2026-09-12', 1000000, 0, 20000);

        $pr = $this->buatPermintaan('PR-202609-0001', '2026-09-15', 1160000, 110000, 50000, ['nomor_po' => 'PO-202609-0001']);
        $this->buatPermintaan('PR-202609-0002', '2026-09-16', 1110000, 110000, 0, ['status' => 'dipesan']);
        $this->buatPermintaan('PR-202609-0003', '2026-09-17', 500000, 0, 0);

        DB::table('pembelian_sparepart')->insert([
            'id_pembelian' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pengajuan' => 'PS-202609-0001',
            'status' => 'lunas', 'total_estimasi' => 555000, 'total_aktual' => 555000, 'ppn' => 55000, 'ppn_persen' => 11,
            'ongkir' => 0, 'tanggal_pengajuan' => '2026-09-18', 'tanggal_pembelian' => '2026-09-18', 'dibuat_pada' => now(),
        ]);
        DB::table('pembelian_sparepart')->insert([
            'id_pembelian' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'nomor_pengajuan' => 'PS-202609-0002',
            'status' => 'lunas', 'total_estimasi' => 1110000, 'total_aktual' => 1110000, 'ppn' => 110000, 'ppn_persen' => 11,
            'ongkir' => 0, 'tanggal_pengajuan' => '2026-09-19', 'tanggal_pembelian' => '2026-09-19',
            'id_permintaan_pembelian' => $pr, 'dibuat_pada' => now(),
        ]);

        $periode = $this->buatSlip('2026-09-30', 12000000, 350000);
        $this->buatSlip('2026-09-30', 4000000, 0);
        $this->buatSlip('2026-09-28', 9000000, 200000, 'draft');

        $this->buatPembayaranFaktur($fakturPpn, '2026-09-25', 10900000, 200000, 'PPh 23 2%');
        $this->buatPembayaranFaktur($fakturPpn, '2026-09-26', 0, 6500, 'Biaya transfer bank');

        return ['faktur' => $fakturPpn, 'invoice_vendor' => $invoiceVendor, 'pr' => $pr, 'periode' => $periode];
    }

    public function test_rekap_menghitung_ppn_dan_pph_per_masa(): void
    {
        $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $res = $this->getJson('/api/rekap-pajak?dari=2026-09&sampai=2026-09')->assertStatus(200);

        $res->assertJsonPath('data.periode.label', 'Masa September 2026')
            ->assertJsonPath('data.ringkasan.ppn_keluaran.pajak', 1870000)
            ->assertJsonPath('data.ringkasan.ppn_keluaran.dpp', 17000000)
            ->assertJsonPath('data.ringkasan.ppn_keluaran.jumlah', 3)
            ->assertJsonPath('data.ringkasan.ppn_masukan.pajak', 2365000)
            ->assertJsonPath('data.ringkasan.ppn_masukan.dpp', 21500000)
            ->assertJsonPath('data.ringkasan.ppn_masukan.jumlah', 3)
            ->assertJsonPath('data.ringkasan.ppn_kurang_bayar', -495000)
            ->assertJsonPath('data.ringkasan.pph_vendor.pajak', 420000)
            ->assertJsonPath('data.ringkasan.pph_vendor.jumlah', 2)
            ->assertJsonPath('data.ringkasan.pph21.pajak', 350000)
            ->assertJsonPath('data.ringkasan.pph21.dpp', 12000000)
            ->assertJsonPath('data.ringkasan.pph21.jumlah', 1)
            ->assertJsonPath('data.ringkasan.pph_dipotong_klien.pajak', 200000)
            ->assertJsonPath('data.ringkasan.pph_dipotong_klien.jumlah', 1)
            ->assertJsonPath('data.ringkasan.pajak_lain.pajak', 50000)
            ->assertJsonPath('data.ringkasan.invoice_tanpa_pajak.jumlah', 1)
            ->assertJsonPath('data.ringkasan.invoice_tanpa_pajak.dpp', 3000000)
            ->assertJsonPath('data.ringkasan.potongan_lain_klien.jumlah', 1)
            ->assertJsonPath('data.ringkasan.potongan_lain_klien.nominal', 6500)
            ->assertJsonCount(1, 'data.per_masa')
            ->assertJsonPath('data.per_masa.0.masa', '2026-09')
            ->assertJsonPath('data.per_masa.0.label', 'September 2026')
            ->assertJsonPath('data.per_masa.0.ppn_kurang_bayar', -495000);
        $this->assertArrayNotHasKey('baris', $res->json('data'));

        $duaMasa = $this->getJson('/api/rekap-pajak?dari=2026-09&sampai=2026-10')->assertStatus(200);
        $duaMasa->assertJsonCount(2, 'data.per_masa')
            ->assertJsonPath('data.per_masa.1.masa', '2026-10')
            ->assertJsonPath('data.per_masa.1.ppn_keluaran', 440000)
            ->assertJsonPath('data.per_masa.1.ppn_masukan', 0)
            ->assertJsonPath('data.ringkasan.ppn_keluaran.pajak', 2310000);
    }

    public function test_rincian_per_jenis_memuat_tautan_dokumen_dan_bisa_dicari(): void
    {
        $id = $this->siapkanData();
        $this->actingAsRole('KEUANGAN');
        $dasar = '/api/rekap-pajak/rincian?dari=2026-09&sampai=2026-09';

        $keluaran = $this->getJson($dasar . '&jenis=ppn_keluaran')->assertStatus(200);
        $keluaran->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.total.pajak', 1870000)
            ->assertJsonPath('data.data.0.nomor', 'INV-202609-0006')
            ->assertJsonPath('data.data.0.keterangan', 'Pajak')
            ->assertJsonPath('data.data.2.nomor', 'INV-202609-0001')
            ->assertJsonPath('data.data.2.pajak', 1100000)
            ->assertJsonPath('data.data.2.tarif', 11)
            ->assertJsonPath('data.data.2.link', '/faktur/' . $id['faktur']);

        $masukan = $this->getJson($dasar . '&jenis=ppn_masukan')->assertStatus(200);
        $masukan->assertJsonPath('data.meta.total', 3);
        $barisMasukan = collect($masukan->json('data.data'))->keyBy('nomor');
        $this->assertEquals(1000000, $barisMasukan['PO-202609-0001']['dpp']);
        $this->assertSame('PR PR-202609-0001', $barisMasukan['PO-202609-0001']['keterangan']);
        $this->assertSame('/permintaan-pembelian/' . $id['pr'], $barisMasukan['PO-202609-0001']['link']);
        $this->assertSame('Toko Maju', $barisMasukan['PO-202609-0001']['pihak']);
        $this->assertSame('01.234.567.8-901.000', $barisMasukan['IV-2026-0001']['npwp']);
        $this->assertSame('/invoice-vendor/' . $id['invoice_vendor'], $barisMasukan['IV-2026-0001']['link']);
        $this->assertEquals(500000, $barisMasukan['PS-202609-0001']['dpp']);
        $this->assertArrayNotHasKey('PS-202609-0002', $barisMasukan->all());

        $this->getJson($dasar . '&jenis=pph_vendor')
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.data.1.tarif', 2);

        $this->getJson($dasar . '&jenis=pph21')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.pihak', 'Budi Santoso')
            ->assertJsonPath('data.data.0.npwp', '09.876.543.2-100.000')
            ->assertJsonPath('data.data.0.keterangan', 'NIK 3171000000000001 · PTKP K/1')
            ->assertJsonPath('data.data.0.link', '/payroll/' . $id['periode']);

        $this->getJson($dasar . '&jenis=pph_dipotong_klien')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.keterangan', 'PPh 23 2%')
            ->assertJsonPath('data.data.0.pajak', 200000)
            ->assertJsonPath('data.data.0.dpp', 10900000);

        $this->getJson($dasar . '&jenis=pajak_lain')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.keterangan', 'Biaya Admin');

        $this->getJson($dasar . '&jenis=ppn_keluaran&search=0001')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.total.pajak', 1100000);
        $this->getJson($dasar . '&jenis=ppn_keluaran&limit=1&page=2')->assertJsonCount(1, 'data.data')->assertJsonPath('data.meta.totalPages', 3);
    }

    public function test_validasi_masa_dan_izin(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->getJson('/api/rekap-pajak')->assertStatus(200)->assertJsonPath('data.periode.dari', now()->format('Y-m'));
        $this->getJson('/api/rekap-pajak?dari=2026-10&sampai=2026-09')->assertStatus(422);
        $this->getJson('/api/rekap-pajak?dari=2025-01&sampai=2026-01')->assertStatus(422);
        $this->getJson('/api/rekap-pajak?dari=2026-01&sampai=2026-12')->assertStatus(200)->assertJsonCount(12, 'data.per_masa');
        $this->getJson('/api/rekap-pajak?dari=2026-13')->assertStatus(422);
        $this->getJson('/api/rekap-pajak/rincian?jenis=pajak_hiburan')->assertStatus(422);

        $this->actingAsRole('MANAGER');
        $this->getJson('/api/rekap-pajak')->assertStatus(200);

        $this->actingAsRole('SALES');
        $this->getJson('/api/rekap-pajak')->assertStatus(403);
        $this->getJson('/api/rekap-pajak/rincian?jenis=pph21')->assertStatus(403);
    }

    public function test_data_perusahaan_lain_tidak_ikut_terhitung(): void
    {
        $this->siapkanData();
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->buatFaktur('INV-LAIN-0001', '2026-09-05', 90000000, [['PPN', 11]], ['id_perusahaan' => $idLain, 'id_klien' => $this->buatKlien($idLain)]);
        $this->buatInvoiceVendor('IV-LAIN-0001', '2026-09-10', 70000000, 7700000, 1400000, ['id_perusahaan' => $idLain]);

        $this->actingAsRole('KEUANGAN');
        $this->getJson('/api/rekap-pajak?dari=2026-09&sampai=2026-09')
            ->assertJsonPath('data.ringkasan.ppn_keluaran.pajak', 1870000)
            ->assertJsonPath('data.ringkasan.ppn_masukan.pajak', 2365000)
            ->assertJsonPath('data.ringkasan.pph_vendor.pajak', 420000);
    }

    public function test_export_excel_berisi_sheet_per_jenis_dan_tautan_dokumen(): void
    {
        $id = $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $unduh = $this->get('/api/rekap-pajak/export/excel?dari=2026-09&sampai=2026-09', ['X-Frontend-Url' => 'https://app.contoh.test']);
        $unduh->assertStatus(200);
        $this->assertStringContainsString('rekap-pajak-', (string) $unduh->headers->get('content-disposition'));

        $rekap  = app(RekapPajakService::class)->rekap(self::PERUSAHAAN_ID, '2026-09', '2026-09');
        $berkas = tempnam(sys_get_temp_dir(), 'pjk') . '.xlsx';
        file_put_contents($berkas, Excel::raw(new RekapPajakExport($rekap, 'TMN Transport Test', 'https://app.contoh.test'), \Maatwebsite\Excel\Excel::XLSX));

        try {
            $workbook = IOFactory::load($berkas);
            $this->assertSame(
                ['Ringkasan', 'Per Masa', 'PPN Keluaran', 'PPN Masukan', 'PPh Vendor', 'PPh 21', 'PPh Dipotong Klien', 'Pajak Lain Invoice'],
                $workbook->getSheetNames(),
            );

            $keluaran = $workbook->getSheetByName('PPN Keluaran');
            $this->assertSame('INV-202609-0001', $keluaran->getCell('D4')->getValue());
            $this->assertEquals(1100000, $keluaran->getCell('J4')->getValue());
            $this->assertSame('https://app.contoh.test/faktur/' . $id['faktur'], $keluaran->getCell('L4')->getHyperlink()->getUrl());
            $this->assertSame('TOTAL', $keluaran->getCell('G7')->getValue());
            $this->assertEquals(1870000, $keluaran->getCell('J7')->getValue());

            $ringkasan = $workbook->getSheetByName('Ringkasan');
            $this->assertSame('PPN Kurang/(Lebih) Bayar', $ringkasan->getCell('A6')->getValue());
            $this->assertEquals(-495000, $ringkasan->getCell('C6')->getValue());

            $perMasa = $workbook->getSheetByName('Per Masa');
            $this->assertSame('September 2026', $perMasa->getCell('A4')->getValue());
            $this->assertSame('TOTAL', $perMasa->getCell('A5')->getValue());
        } finally {
            @unlink($berkas);
        }
    }

    public function test_sumber_lain_milik_perusahaan_lain_dan_baris_terhapus_tidak_terhitung(): void
    {
        $id = $this->siapkanData();
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        $this->buatPermintaan('PR-LAIN-0001', '2026-09-15', 22200000, 2200000, 0, ['id_perusahaan' => $idLain]);
        DB::table('pembelian_sparepart')->insert([
            'id_pembelian' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'nomor_pengajuan' => 'PS-LAIN-0001',
            'status' => 'lunas', 'total_estimasi' => 3330000, 'total_aktual' => 3330000, 'ppn' => 330000, 'ppn_persen' => 11,
            'ongkir' => 0, 'tanggal_pengajuan' => '2026-09-18', 'tanggal_pembelian' => '2026-09-18', 'dibuat_pada' => now(),
        ]);
        $idPeriodeLain = (string) Str::uuid();
        DB::table('payroll_periode')->insert([
            'id_periode' => $idPeriodeLain, 'id_perusahaan' => $idLain, 'nama' => 'Payroll Lain', 'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-30', 'status' => 'final', 'dibuat_pada' => now(),
        ]);
        DB::table('payroll_slip')->insert([
            'id_slip' => (string) Str::uuid(), 'id_periode' => $idPeriodeLain, 'id_perusahaan' => $idLain,
            'id_karyawan' => (string) Str::uuid(), 'total_bruto' => 50000000, 'pph21' => 5000000, 'gaji_bersih' => 45000000, 'dibuat_pada' => now(),
        ]);
        $fakturLain = $this->buatFaktur('INV-LAIN-0002', '2026-09-05', 30000000, [['PPN', 11]], ['id_perusahaan' => $idLain, 'id_klien' => $this->buatKlien($idLain)]);
        $this->buatPembayaranFaktur($fakturLain, '2026-09-25', 29000000, 600000, 'PPh 23');

        $this->buatFaktur('INV-202609-0091', '2026-09-06', 70000000, [['PPN', 11]], ['dihapus_pada' => now()]);
        $this->buatInvoiceVendor('IV-2026-0091', '2026-09-13', 60000000, 6600000, 1200000, ['dihapus_pada' => now()]);
        $this->buatPermintaan('PR-202609-0091', '2026-09-15', 11100000, 1100000, 0, ['dihapus_pada' => now()]);
        DB::table('faktur_pajak')->insert([
            'id_faktur_pajak' => (string) Str::uuid(), 'id_faktur' => $id['faktur'], 'nama' => 'PPN lama', 'persen' => 10,
            'urutan' => 2, 'dibuat_pada' => now(), 'dihapus_pada' => now(),
        ]);
        DB::table('faktur_item')->insert([
            'id_faktur_item' => (string) Str::uuid(), 'id_faktur' => $id['faktur'], 'deskripsi' => 'Baris lama',
            'qty' => 1, 'harga_satuan' => 99000000, 'subtotal' => 99000000, 'dibuat_pada' => now(), 'dihapus_pada' => now(),
        ]);

        $this->actingAsRole('KEUANGAN');
        $this->getJson('/api/rekap-pajak?dari=2026-09&sampai=2026-09')
            ->assertStatus(200)
            ->assertJsonPath('data.ringkasan.ppn_keluaran.pajak', 1870000)
            ->assertJsonPath('data.ringkasan.ppn_keluaran.dpp', 17000000)
            ->assertJsonPath('data.ringkasan.ppn_masukan.pajak', 2365000)
            ->assertJsonPath('data.ringkasan.pph_vendor.pajak', 420000)
            ->assertJsonPath('data.ringkasan.pph21.pajak', 350000)
            ->assertJsonPath('data.ringkasan.pph_dipotong_klien.pajak', 200000);
    }

    public function test_parameter_dibaca_dari_hasil_validasi_bukan_query_mentah(): void
    {
        $this->siapkanData();
        $this->actingAsRole('KEUANGAN');

        $this->json('GET', '/api/rekap-pajak/rincian?jenis=pajak_hiburan&dari=2026-09&sampai=2026-09', ['jenis' => 'ppn_keluaran'])
            ->assertStatus(200)
            ->assertJsonPath('data.jenis', 'ppn_keluaran');
        $this->json('GET', '/api/rekap-pajak?dari=abc', ['dari' => '2026-09', 'sampai' => '2026-09'])
            ->assertStatus(200)
            ->assertJsonPath('data.periode.dari', '2026-09');
        $this->getJson('/api/rekap-pajak?dari=2026-99')->assertStatus(422);
        $this->getJson('/api/rekap-pajak/rincian?jenis=ppn_keluaran&limit=1000')->assertStatus(422);
    }

    public function test_excel_menjaga_npwp_sebagai_teks_dan_menulis_nilai_nol(): void
    {
        $this->ensurePerusahaan();
        $this->buatInvoiceVendor('88999', '2026-09-10', 20000000, 2200000, 0);
        DB::table('vendor')->update(['npwp' => '3171234567890001']);
        $this->actingAsRole('KEUANGAN');

        $unduh = $this->get('/api/rekap-pajak/export/excel?dari=2026-09&sampai=2026-10', ['X-Frontend-Url' => 'https://app.contoh.test']);
        $unduh->assertStatus(200);
        $berkas   = $unduh->baseResponse->getFile()->getPathname();
        $workbook = IOFactory::load($berkas);
        $this->assertSame('PPN Masukan', $workbook->getSheetNames()[3]);

        $masukan = $workbook->getSheetByName('PPN Masukan');
        $this->assertSame('88999', $masukan->getCell('D4')->getValue());
        $this->assertSame('3171234567890001', $masukan->getCell('F4')->getValue());
        $this->assertTrue($this->selExcelBerupaTeks($berkas, 4, 'D4'));
        $this->assertTrue($this->selExcelBerupaTeks($berkas, 4, 'F4'));
        $this->assertFalse($this->selExcelBerupaTeks($berkas, 4, 'H4'));
        $this->assertFalse($this->selExcelBerupaTeks($berkas, 4, 'J4'));
        $this->assertFalse($this->selExcelBerupaTeks($berkas, 2, 'B5'));
        $this->assertStringStartsWith('https://app.contoh.test/invoice-vendor/', $masukan->getCell('L4')->getHyperlink()->getUrl());

        $perMasa = $workbook->getSheetByName('Per Masa');
        $this->assertSame('Oktober 2026', $perMasa->getCell('A5')->getValue());
        $this->assertNotNull($perMasa->getCell('B5')->getValue());
        $this->assertSame(0, (int) $perMasa->getCell('B5')->getValue());

        $ringkasan = $workbook->getSheetByName('Ringkasan');
        $this->assertSame('PPh dipotong oleh klien (kredit pajak)', $ringkasan->getCell('A10')->getValue());
        $this->assertSame('', (string) $ringkasan->getCell('B10')->getValue());
        $this->assertSame(-2200000, (int) $ringkasan->getCell('C6')->getValue());
    }
}
