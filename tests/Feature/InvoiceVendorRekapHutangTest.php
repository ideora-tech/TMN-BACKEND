<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class InvoiceVendorRekapHutangTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function makeVendor(string $nama, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_vendor' => 'VDR-' . Str::random(8), 'nama_vendor' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function insertInvoice(string $idVendor, array $isi = []): string
    {
        $id = (string) Str::uuid();
        DB::table('invoice_vendor')->insert(array_merge([
            'id_invoice_vendor' => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_vendor'         => $idVendor,
            'nomor_invoice'     => 'INV-' . Str::random(10),
            'tanggal_invoice'   => '2026-09-01',
            'dpp'               => 1000000,
            'ppn'               => 0,
            'pph'               => 20000,
            'total'             => 980000,
            'status'            => 'diverifikasi',
            'status_pembayaran' => 'belum',
            'dibuat_pada'       => '2026-09-01 08:00:00',
        ], $isi));

        return $id;
    }

    private function unduh(string $bulan): Worksheet
    {
        $unduh = $this->get("/api/invoice-vendor/export/rekap-hutang?bulan={$bulan}");
        $unduh->assertStatus(200);
        $this->assertStringContainsString("hutang-pph-vendor-{$bulan}.xlsx", (string) $unduh->headers->get('content-disposition'));

        return IOFactory::load($unduh->baseResponse->getFile()->getPathname())->getActiveSheet();
    }

    public function test_rekap_hutang_berisi_invoice_diverifikasi_pada_bulan_itu(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $zulfitri = $this->makeVendor('Zulfitri');
        $opptima  = $this->makeVendor('PT Opptima Rent');

        $this->insertInvoice($zulfitri, [
            'nomor_invoice' => '007-ZUL/SLI/09/26', 'keterangan' => 'Sewa unit Blindvan',
            'dpp' => 32750000, 'pph' => 655000, 'total' => 32095000, 'dibuat_pada' => '2026-09-01 08:00:00',
        ]);
        $this->insertInvoice($opptima, [
            'nomor_invoice' => '2609217730', 'tanggal_invoice' => '2026-09-01', 'tipe_kendaraan' => 'Isuzu',
            'dpp' => 112000000, 'pph' => 2240000, 'total' => 109760000, 'dibuat_pada' => '2026-09-01 09:00:00',
        ]);
        $this->insertInvoice($opptima, [
            'nomor_invoice' => '2609217749', 'tanggal_invoice' => '2026-09-30', 'keterangan' => 'Sewa unit Hino Box',
            'dpp' => 9333333.33, 'pph' => 186666.67, 'total' => 9146666.66,
        ]);

        $this->insertInvoice($zulfitri, ['status' => 'draft']);
        $this->insertInvoice($zulfitri, ['status' => 'menunggu_approval']);
        $this->insertInvoice($zulfitri, ['status' => 'ditolak']);
        $this->insertInvoice($zulfitri, ['status' => 'dibatalkan']);
        $this->insertInvoice($zulfitri, ['tanggal_invoice' => '2026-08-31']);
        $this->insertInvoice($zulfitri, ['tanggal_invoice' => '2026-10-01']);
        $this->insertInvoice($zulfitri, ['dihapus_pada' => now()]);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->insertInvoice($this->makeVendor('Vendor Lain', $idPerusahaanLain), ['id_perusahaan' => $idPerusahaanLain]);

        $sheet = $this->unduh('2026-09');

        $this->assertSame('RINCIAN HUTANG DAN PPH VENDOR', $sheet->getCell('A1')->getValue());
        $this->assertSame('PERIODE SEPTEMBER 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame(
            ['NO', 'TANGGAL', 'INVOICE', 'VENDOR', 'KETERANGAN', 'DPP', 'PPH', 'TOTAL'],
            $sheet->rangeToArray('A3:H3', null, false, false)[0],
        );
        $this->assertNull($sheet->getCell('I3')->getValue());

        $this->assertEquals(1, $sheet->getCell('A4')->getValue());
        $this->assertSame('01 September 2026', $sheet->getCell('B4')->getValue());
        $this->assertSame('007-ZUL/SLI/09/26', $sheet->getCell('C4')->getValue());
        $this->assertSame('ZULFITRI', $sheet->getCell('D4')->getValue());
        $this->assertSame('SEWA UNIT BLINDVAN', $sheet->getCell('E4')->getValue());
        $this->assertEquals(32750000, $sheet->getCell('F4')->getValue());
        $this->assertEquals(655000, $sheet->getCell('G4')->getValue());
        $this->assertEquals(32095000, $sheet->getCell('H4')->getValue());

        $this->assertEquals(2, $sheet->getCell('A5')->getValue());
        $this->assertSame('2609217730', $sheet->getCell('C5')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C5')->getDataType());
        $this->assertSame('PT OPPTIMA RENT', $sheet->getCell('D5')->getValue());
        $this->assertSame('SEWA UNIT ISUZU', $sheet->getCell('E5')->getValue());

        $this->assertSame('30 September 2026', $sheet->getCell('B6')->getValue());
        $this->assertEquals(9333333, $sheet->getCell('F6')->getValue());
        $this->assertEquals(186667, $sheet->getCell('G6')->getValue());
        $this->assertEquals(9146666, $sheet->getCell('H6')->getValue());

        $this->assertSame('TOTAL', $sheet->getCell('A8')->getValue());
        $this->assertEquals(154083333, $sheet->getCell('F8')->getValue());
        $this->assertEquals(3081667, $sheet->getCell('G8')->getValue());
        $this->assertEquals(151001666, $sheet->getCell('H8')->getValue());
        $this->assertSame(8, $sheet->getHighestRow());
        $this->assertArrayHasKey('A8:E8', $sheet->getMergeCells());
        $this->assertArrayHasKey('A1:H1', $sheet->getMergeCells());
        $this->assertSame('A3:H6', $sheet->getAutoFilter()->getRange());
        $this->assertStringContainsString('"Rp"', $sheet->getStyle('H8')->getNumberFormat()->getFormatCode());
        $this->assertTrue($sheet->getStyle('F8')->getFont()->getBold());
    }

    public function test_rekap_hutang_menambah_kolom_ppn_bila_ada_invoice_ber_ppn(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor('PT Vendor PKP');
        $this->insertInvoice($vendor, ['nomor_invoice' => 'A-1', 'dpp' => 10000000, 'ppn' => 1100000, 'pph' => 200000, 'total' => 10900000]);
        $this->insertInvoice($vendor, ['nomor_invoice' => 'A-2', 'dpp' => 5000000, 'ppn' => 0, 'pph' => 0, 'total' => 5000000, 'dibuat_pada' => '2026-09-01 09:00:00']);

        $sheet = $this->unduh('2026-09');

        $this->assertSame(
            ['NO', 'TANGGAL', 'INVOICE', 'VENDOR', 'KETERANGAN', 'DPP', 'PPN', 'PPH', 'TOTAL'],
            $sheet->rangeToArray('A3:I3', null, false, false)[0],
        );
        $this->assertEquals(1100000, $sheet->getCell('G4')->getValue());
        $this->assertEquals(200000, $sheet->getCell('H4')->getValue());
        $this->assertEquals(10900000, $sheet->getCell('I4')->getValue());
        $this->assertNotNull($sheet->getCell('G5')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('G5')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('H5')->getValue());
        $this->assertEquals(5000000, $sheet->getCell('I5')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('A7')->getValue());
        $this->assertEquals(15000000, $sheet->getCell('F7')->getValue());
        $this->assertEquals(1100000, $sheet->getCell('G7')->getValue());
        $this->assertEquals(200000, $sheet->getCell('H7')->getValue());
        $this->assertEquals(15900000, $sheet->getCell('I7')->getValue());
        $this->assertArrayHasKey('A1:I1', $sheet->getMergeCells());
    }

    public function test_rekap_hutang_tanpa_invoice_tetap_terunduh_dengan_total_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $sheet = $this->unduh('2026-02');

        $this->assertSame('PERIODE FEBRUARI 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('A5')->getValue());
        foreach (['F5', 'G5', 'H5'] as $sel) {
            $this->assertNotNull($sheet->getCell($sel)->getValue());
            $this->assertSame(0.0, (float) $sheet->getCell($sel)->getValue());
        }
    }

    public function test_rekap_hutang_memvalidasi_bulan_dan_izin_menu_invoice_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->getJson('/api/invoice-vendor/export/rekap-hutang')->assertStatus(422)->assertJsonValidationErrors(['bulan']);
        $this->getJson('/api/invoice-vendor/export/rekap-hutang?bulan=2026-13')->assertStatus(422)->assertJsonValidationErrors(['bulan']);
        $this->getJson('/api/invoice-vendor/export/rekap-hutang?bulan[]=2026-09')->assertStatus(422)->assertJsonValidationErrors(['bulan']);

        $this->actingAsRole('SUPIR');
        $this->get('/api/invoice-vendor/export/rekap-hutang?bulan=2026-09')->assertStatus(403);
    }
}
