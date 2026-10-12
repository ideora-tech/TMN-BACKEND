<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Faktur\FakturModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FakturRekapBulananTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function makeKlien(string $nama, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_klien' => 'KLN-' . Str::random(6), 'nama_klien' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function makeProyek(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => $nama, 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function makeFaktur(array $isi, ?float $dpp = null, ?float $persenPajak = null): FakturModel
    {
        $faktur = FakturModel::create(array_merge([
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'nomor_faktur'   => 'INV-' . Str::upper(Str::random(8)),
            'total'          => 0,
            'status'         => 'terkirim',
            'tanggal_faktur' => '2026-08-06',
        ], $isi));

        if ($dpp !== null) {
            DB::table('faktur_item')->insert([
                'id_faktur_item' => (string) Str::uuid(), 'id_faktur' => $faktur->id_faktur,
                'deskripsi' => 'Jasa angkutan', 'qty' => 1, 'harga_satuan' => $dpp, 'subtotal' => $dpp,
            ]);
        }

        if ($persenPajak !== null) {
            DB::table('faktur_pajak')->insert([
                'id_faktur_pajak' => (string) Str::uuid(), 'id_faktur' => $faktur->id_faktur,
                'nama' => 'PPN', 'persen' => $persenPajak, 'urutan' => 1, 'dibuat_pada' => now(),
            ]);
        }

        return $faktur;
    }

    public function test_rekap_bulanan_berisi_invoice_terkirim_dan_lunas_pada_bulan_itu(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien('PT Hokkan Deltapack');
        $proyek = $this->makeProyek('Oncall');

        $this->makeFaktur([
            'id_klien' => $klien, 'id_proyek' => $proyek, 'nomor_faktur' => '090/SLI/INV-TP/VIII/2026',
            'nomor_faktur_pajak' => '050.026.003.03651896', 'total' => 1769250,
        ], 1750000, 1.1);
        $this->makeFaktur([
            'id_klien' => $klien, 'nomor_faktur' => '102/SLI/INV-TP/VIII/2026', 'nomor_faktur_pajak' => '04002600012345678',
            'total' => 94350000, 'status' => 'lunas', 'tanggal_faktur' => '2026-08-13',
        ], 85000000, 11);
        $this->makeFaktur([
            'id_klien' => $klien, 'nomor_faktur' => '113/SLI/INV-TP/VIII/2026', 'total' => 11100000, 'tanggal_faktur' => '2026-08-31',
        ], null, 11);

        $this->makeFaktur(['id_klien' => $klien, 'status' => 'draft', 'total' => 500000], 500000);
        $this->makeFaktur(['id_klien' => $klien, 'status' => 'menunggu_approval', 'total' => 500000], 500000);
        $this->makeFaktur(['id_klien' => $klien, 'status' => 'batal', 'total' => 500000], 500000);
        $this->makeFaktur(['id_klien' => $klien, 'total' => 500000, 'tanggal_faktur' => '2026-07-31'], 500000);
        $this->makeFaktur(['id_klien' => $klien, 'total' => 500000, 'tanggal_faktur' => '2026-09-01'], 500000);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeFaktur(['id_perusahaan' => $idPerusahaanLain, 'id_klien' => $this->makeKlien('Klien Lain', $idPerusahaanLain), 'total' => 900000], 900000);

        $unduh = $this->get('/api/faktur/export/rekap-bulanan?bulan=2026-08');

        $unduh->assertStatus(200);
        $this->assertStringContainsString('piutang-2026-08.xlsx', (string) $unduh->headers->get('content-disposition'));

        $sheet = IOFactory::load($unduh->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertSame('PIUTANG BULAN AGUSTUS 2026', $sheet->getCell('A1')->getValue());
        $this->assertSame(
            ['TANGGAL', 'NO FAKTUR PAJAK', 'NO INVOICE', 'NAMA PERUSAHAAN', 'PROJECT', 'DPP', 'PPN', 'TOTAL', 'PAJAK PPH 2%', 'SUB TOTAL'],
            $sheet->rangeToArray('A3:J3', null, false, false)[0],
        );

        $this->assertSame('06 AGUSTUS 2026', $sheet->getCell('A4')->getValue());
        $this->assertSame('050.026.003.03651896', $sheet->getCell('B4')->getValue());
        $this->assertSame('090/SLI/INV-TP/VIII/2026', $sheet->getCell('C4')->getValue());
        $this->assertSame('PT HOKKAN DELTAPACK', $sheet->getCell('D4')->getValue());
        $this->assertSame('ONCALL', $sheet->getCell('E4')->getValue());
        $this->assertEquals(1750000, $sheet->getCell('F4')->getValue());
        $this->assertEquals(19250, $sheet->getCell('G4')->getValue());
        $this->assertEquals(1769250, $sheet->getCell('H4')->getValue());
        $this->assertEquals(35000, $sheet->getCell('I4')->getValue());
        $this->assertEquals(1734250, $sheet->getCell('J4')->getValue());

        $this->assertSame('13 AGUSTUS 2026', $sheet->getCell('A5')->getValue());
        $this->assertSame('04002600012345678', $sheet->getCell('B5')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B5')->getDataType());
        $this->assertEquals(85000000, $sheet->getCell('F5')->getValue());
        $this->assertEquals(9350000, $sheet->getCell('G5')->getValue());
        $this->assertEquals(94350000, $sheet->getCell('H5')->getValue());
        $this->assertEquals(1700000, $sheet->getCell('I5')->getValue());
        $this->assertEquals(92650000, $sheet->getCell('J5')->getValue());

        $this->assertSame('31 AGUSTUS 2026', $sheet->getCell('A6')->getValue());
        $this->assertEquals(10000000, $sheet->getCell('F6')->getValue());
        $this->assertEquals(1100000, $sheet->getCell('G6')->getValue());
        $this->assertEquals(11100000, $sheet->getCell('H6')->getValue());
        $this->assertEquals(200000, $sheet->getCell('I6')->getValue());
        $this->assertEquals(10900000, $sheet->getCell('J6')->getValue());

        $this->assertSame('TOTAL', $sheet->getCell('A7')->getValue());
        $this->assertEquals(96750000, $sheet->getCell('F7')->getValue());
        $this->assertEquals(10469250, $sheet->getCell('G7')->getValue());
        $this->assertEquals(107219250, $sheet->getCell('H7')->getValue());
        $this->assertEquals(1935000, $sheet->getCell('I7')->getValue());
        $this->assertEquals(105284250, $sheet->getCell('J7')->getValue());
        $this->assertSame(7, $sheet->getHighestRow());
        $this->assertArrayHasKey('A7:E7', $sheet->getMergeCells());
        $this->assertArrayHasKey('A1:J1', $sheet->getMergeCells());
        $this->assertSame('EBC7EB', $sheet->getStyle('A3')->getFill()->getStartColor()->getRGB());
        $this->assertSame('EBC7EB', $sheet->getStyle('J3')->getFill()->getStartColor()->getRGB());
        $this->assertStringContainsString('"Rp"', $sheet->getStyle('F4')->getNumberFormat()->getFormatCode());
        $this->assertStringContainsString('"Rp"', $sheet->getStyle('J7')->getNumberFormat()->getFormatCode());
        $this->assertTrue($sheet->getStyle('H7')->getFont()->getBold());
    }

    public function test_rekap_bulanan_tanpa_invoice_tetap_terunduh_dengan_total_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $unduh = $this->get('/api/faktur/export/rekap-bulanan?bulan=2026-02');

        $unduh->assertStatus(200);
        $sheet = IOFactory::load($unduh->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('PIUTANG BULAN FEBRUARI 2026', $sheet->getCell('A1')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('A4')->getValue());
        foreach (['F4', 'G4', 'H4', 'I4', 'J4'] as $sel) {
            $this->assertNotNull($sheet->getCell($sel)->getValue());
            $this->assertSame(0.0, (float) $sheet->getCell($sel)->getValue());
        }
    }

    public function test_rekap_bulanan_membulatkan_ke_rupiah_penuh_agar_jumlah_cocok_dengan_barisnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien('PT Klien Uji');
        $this->makeFaktur(['id_klien' => $klien, 'nomor_faktur' => 'INV-A', 'total' => 1000025], 1000025);
        $this->makeFaktur(['id_klien' => $klien, 'nomor_faktur' => 'INV-B', 'total' => 1000025], 1000025);
        $this->makeFaktur(['id_klien' => $klien, 'nomor_faktur' => 'INV-C', 'total' => 258404850 + 2842453.35], 258404850, 1.1);

        $unduh = $this->get('/api/faktur/export/rekap-bulanan?bulan=2026-08');

        $unduh->assertStatus(200);
        $sheet = IOFactory::load($unduh->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertNotNull($sheet->getCell('G4')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('G4')->getValue());
        $this->assertEquals(20001, $sheet->getCell('I4')->getValue());
        $this->assertEquals(980024, $sheet->getCell('J4')->getValue());
        $this->assertEquals(2842453, $sheet->getCell('G6')->getValue());
        $this->assertEquals(261247303, $sheet->getCell('H6')->getValue());
        $this->assertEquals(5168097, $sheet->getCell('I6')->getValue());
        $this->assertEquals(256079206, $sheet->getCell('J6')->getValue());

        $this->assertSame('TOTAL', $sheet->getCell('A7')->getValue());
        foreach (['F', 'G', 'H', 'I', 'J'] as $kolom) {
            $jumlahBaris = 0.0;
            foreach ([4, 5, 6] as $nomorBaris) {
                $nilai = (float) $sheet->getCell($kolom . $nomorBaris)->getValue();
                $this->assertSame(round($nilai), $nilai);
                $jumlahBaris += $nilai;
            }
            $this->assertSame($jumlahBaris, (float) $sheet->getCell($kolom . '7')->getValue());
        }
    }

    public function test_rekap_bulanan_memvalidasi_bulan_dan_izin_menu_invoice(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->getJson('/api/faktur/export/rekap-bulanan')->assertStatus(422)->assertJsonValidationErrors(['bulan']);
        $this->getJson('/api/faktur/export/rekap-bulanan?bulan=2026-13')->assertStatus(422)->assertJsonValidationErrors(['bulan']);
        $this->getJson('/api/faktur/export/rekap-bulanan?bulan=agustus')->assertStatus(422)->assertJsonValidationErrors(['bulan']);
        $this->getJson('/api/faktur/export/rekap-bulanan?bulan[]=2026-08')->assertStatus(422)->assertJsonValidationErrors(['bulan']);

        $this->actingAsRole('DISPATCHER');
        $this->get('/api/faktur/export/rekap-bulanan?bulan=2026-08')->assertStatus(403);
    }

    public function test_nomor_faktur_pajak_bisa_diisi_setelah_invoice_terkirim(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $faktur = $this->makeFaktur(['id_klien' => $this->makeKlien('PT Klien Uji'), 'total' => 1000000], 1000000);
        $url = "/api/faktur/{$faktur->id_faktur}/nomor-faktur-pajak";

        $this->patchJson($url, ['nomor_faktur_pajak' => '  050.026.003.03651896  '])
            ->assertStatus(200)
            ->assertJsonPath('data.nomor_faktur_pajak', '050.026.003.03651896')
            ->assertJsonPath('data.status', 'terkirim');
        $this->assertDatabaseHas('faktur', ['id_faktur' => $faktur->id_faktur, 'nomor_faktur_pajak' => '050.026.003.03651896']);
        $this->assertSame(1, DB::table('faktur_status_log')->where('id_faktur', $faktur->id_faktur)->where('status', 'diedit')->count());

        $this->patchJson($url, ['nomor_faktur_pajak' => '050.026.003.03651896'])->assertStatus(200);
        $this->assertSame(1, DB::table('faktur_status_log')->where('id_faktur', $faktur->id_faktur)->where('status', 'diedit')->count());

        $this->getJson("/api/faktur/{$faktur->id_faktur}")->assertJsonPath('data.nomor_faktur_pajak', '050.026.003.03651896');

        $this->patchJson($url, ['nomor_faktur_pajak' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.nomor_faktur_pajak', null);
        $this->assertSame(2, DB::table('faktur_status_log')->where('id_faktur', $faktur->id_faktur)->where('status', 'diedit')->count());
    }

    public function test_nomor_faktur_pajak_ditolak_untuk_invoice_batal_perusahaan_lain_dan_isian_tidak_sah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien('PT Klien Uji');
        $faktur = $this->makeFaktur(['id_klien' => $klien, 'total' => 1000000], 1000000);
        $url = "/api/faktur/{$faktur->id_faktur}/nomor-faktur-pajak";

        $this->patchJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['nomor_faktur_pajak']);
        $this->patchJson($url, ['nomor_faktur_pajak' => str_repeat('1', 51)])->assertStatus(422)->assertJsonValidationErrors(['nomor_faktur_pajak']);
        $this->patchJson($url, ['nomor_faktur_pajak' => ['050']])->assertStatus(422)->assertJsonValidationErrors(['nomor_faktur_pajak']);

        $batal = $this->makeFaktur(['id_klien' => $klien, 'status' => 'batal', 'total' => 1000000], 1000000);
        $this->patchJson("/api/faktur/{$batal->id_faktur}/nomor-faktur-pajak", ['nomor_faktur_pajak' => '050.026'])->assertStatus(422);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $lain = $this->makeFaktur(['id_perusahaan' => $idPerusahaanLain, 'id_klien' => $this->makeKlien('Klien Lain', $idPerusahaanLain), 'total' => 900000], 900000);
        $this->patchJson("/api/faktur/{$lain->id_faktur}/nomor-faktur-pajak", ['nomor_faktur_pajak' => '050.026'])->assertStatus(404);

        $this->assertSame(0, DB::table('faktur')->whereNotNull('nomor_faktur_pajak')->count());

        $this->actingAsRole('DISPATCHER');
        $this->patchJson($url, ['nomor_faktur_pajak' => '050.026'])->assertStatus(403);
    }
}
