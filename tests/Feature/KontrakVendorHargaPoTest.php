<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class KontrakVendorHargaPoTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS_UNIT = [
        'kode_vendor', 'nopol', 'merk', 'jenis', 'jenis_kendaraan',
        'kapasitas', 'tahun', 'masa_berlaku_stnk', 'masa_berlaku_kir', 'harga',
    ];

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'PT Vendor Harga',
            'alamat'        => 'Jl. Raya Cakung No. 9',
        ]);
    }

    private function unit(string $nopol, ?float $harga = null, array $override = []): array
    {
        return array_merge([
            'nopol' => $nopol, 'merk' => 'Hino', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02',
            'harga_kontrak' => $harga,
        ], $override);
    }

    private function buatKontrak(string $idVendor, array $unit, array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/kontrak-vendor', array_merge([
            'id_vendor' => $idVendor, 'mekanisme' => 'unit_only', 'nomor_kontrak' => 'KV-' . Str::random(6),
            'nilai_kontrak' => 1000000, 'satuan' => 'per bulan',
            'tanggal_mulai' => '2026-10-01', 'tanggal_selesai' => '2027-09-30', 'unit' => $unit,
        ], $override));
    }

    private function nilai(string $idKontrak): float
    {
        return (float) DB::table('kontrak_vendor')->where('id_kontrak_vendor', $idKontrak)->value('nilai_kontrak');
    }

    private function statusKontrak(string $idKontrak): string
    {
        return (string) DB::table('kontrak_vendor')->where('id_kontrak_vendor', $idKontrak)->value('status');
    }

    private function idUnit(string $nopol): string
    {
        return (string) DB::table('armada_vendor')->where('nopol', $nopol)->whereNull('dihapus_pada')->value('id_armada_vendor');
    }

    private function aktifkan(string $idKontrak): void
    {
        $this->postJson("/api/kontrak-vendor/{$idKontrak}/ajukan-approval")->assertStatus(200)->assertJsonPath('data.status', 'aktif');
    }

    private function makeXlsx(array $rows, array $headings): UploadedFile
    {
        $export = new class($rows, $headings) implements FromArray, WithHeadings {
            public function __construct(private array $rows, private array $headings) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headings;
            }
        };

        $path = sys_get_temp_dir() . '/' . Str::random(10) . '.xlsx';
        file_put_contents($path, Excel::raw($export, ExcelWriterType::XLSX));

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_nilai_kontrak_dihitung_dari_harga_unit_saat_semua_unit_berharga(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 1001 HU', 15000000),
            $this->unit('B 1002 HU', 17500000),
        ])->assertStatus(201);

        $id = (string) $res->json('data.id_kontrak_vendor');
        $this->assertEquals(32500000, $res->json('data.nilai_kontrak'));
        $this->assertEquals(32500000, $this->nilai($id));
        $this->assertEquals(15000000, ArmadaVendorModel::where('nopol', 'B 1001 HU')->value('harga_kontrak'));

        $daftar = collect($this->getJson('/api/armada-vendor?limit=50&id_vendor=' . $vendor->id_vendor)->assertStatus(200)->json('data'));
        $this->assertEquals(17500000, $daftar->firstWhere('nopol', 'B 1002 HU')['harga_kontrak']);

        $this->putJson("/api/kontrak-vendor/{$id}", ['nilai_kontrak' => 99, 'jenis_layanan' => 'Sewa bulanan'])->assertStatus(200)
            ->assertJsonPath('data.jenis_layanan', 'Sewa bulanan');
        $this->assertEquals(32500000, $this->nilai($id));
    }

    public function test_harga_sebagian_ditolak_dan_tanpa_harga_nilai_tetap_manual(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $tolak = $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 2001 HU', 15000000),
            $this->unit('B 2002 HU'),
        ])->assertStatus(422);
        $this->assertStringContainsString('semua unit', (string) $tolak->json('message'));
        $this->assertSame(0, DB::table('kontrak_vendor')->count());

        $res = $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 2003 HU'),
            $this->unit('B 2004 HU', 0),
        ], ['nilai_kontrak' => 48000000])->assertStatus(201);
        $this->assertEquals(48000000, $res->json('data.nilai_kontrak'));
        $this->assertNull(ArmadaVendorModel::where('nopol', 'B 2004 HU')->value('harga_kontrak'));

        $id = (string) $res->json('data.id_kontrak_vendor');
        $this->putJson("/api/kontrak-vendor/{$id}", ['nilai_kontrak' => 50000000])->assertStatus(200);
        $this->assertEquals(50000000, $this->nilai($id));
    }

    public function test_tambah_ubah_dan_hapus_unit_menyesuaikan_nilai_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 3001 HU', 10000000),
            $this->unit('B 3002 HU', 12000000),
        ])->assertStatus(201)->json('data.id_kontrak_vendor');

        $this->postJson('/api/armada-vendor', [
            'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $id, 'nopol' => 'B 3003 HU',
            'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02', 'harga_kontrak' => 8000000,
        ])->assertStatus(201)->assertJsonPath('data.harga_kontrak', 8000000);
        $this->assertEquals(30000000, $this->nilai($id));

        $this->postJson('/api/armada-vendor', [
            'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $id, 'nopol' => 'B 3004 HU',
            'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02',
        ])->assertStatus(201)->assertJsonPath('data.harga_kontrak', null);
        $this->assertEquals(30000000, $this->nilai($id));

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 3004 HU'), ['harga_kontrak' => 5000000])->assertStatus(200);
        $this->assertEquals(35000000, $this->nilai($id));

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 3001 HU'), ['harga_kontrak' => 11000000])->assertStatus(200);
        $this->assertEquals(36000000, $this->nilai($id));

        $this->deleteJson('/api/armada-vendor/' . $this->idUnit('B 3002 HU'))->assertStatus(200);
        $this->assertEquals(24000000, $this->nilai($id));

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 3003 HU'), ['harga_kontrak' => null])->assertStatus(200);
        $this->assertEquals(24000000, $this->nilai($id));
        $this->putJson("/api/kontrak-vendor/{$id}", ['nilai_kontrak' => 20000000])->assertStatus(200);
        $this->assertEquals(20000000, $this->nilai($id));
    }

    public function test_harga_unit_berubah_pada_kontrak_aktif_memicu_approval_ulang_tetapi_koreksi_data_tidak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 4001 HU', 10000000),
            $this->unit('B 4002 HU', 12000000),
        ])->assertStatus(201)->json('data.id_kontrak_vendor');
        $this->aktifkan($id);

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 4001 HU'), ['merk' => 'Isuzu', 'harga_kontrak' => 10000000])->assertStatus(200);
        $this->assertSame('aktif', $this->statusKontrak($id));

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 4001 HU'), ['harga_kontrak' => 10500000])->assertStatus(200);
        $this->assertSame('draft', $this->statusKontrak($id));
        $this->assertEquals(22500000, $this->nilai($id));
    }

    public function test_unit_yang_lepas_dari_kontrak_kehilangan_harganya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 5001 HU', 10000000),
            $this->unit('B 5002 HU', 12000000),
        ])->assertStatus(201)->json('data.id_kontrak_vendor');

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 5001 HU'), ['id_kontrak_vendor' => null])->assertStatus(200);
        $this->assertNull(ArmadaVendorModel::where('nopol', 'B 5001 HU')->value('harga_kontrak'));
        $this->assertEquals(12000000, $this->nilai($id));

        $this->postJson('/api/armada-vendor', [
            'id_vendor' => $vendor->id_vendor, 'nopol' => 'B 5003 HU',
            'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02', 'harga_kontrak' => 7000000,
        ])->assertStatus(201)->assertJsonPath('data.harga_kontrak', null);

        $this->deleteJson("/api/kontrak-vendor/{$id}")->assertStatus(200);
        $this->assertNull(ArmadaVendorModel::where('nopol', 'B 5002 HU')->value('harga_kontrak'));
        $this->assertNull(ArmadaVendorModel::where('nopol', 'B 5002 HU')->value('id_kontrak_vendor'));
    }

    public function test_excel_unit_membaca_kolom_harga_dan_timpa_unit_menghitung_ulang_nilai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $parse = $this->postJson('/api/kontrak-vendor/parse-unit', ['file' => $this->makeXlsx([
            ['', 'B 6001 HU', '', '', '', '', '', '2027-06-01', '2027-06-02', 15000000],
            ['', 'B 6002 HU', '', '', '', '', '', '2027-06-01', '2027-06-02', 'Rp 12.500.000'],
            ['', 'B 6003 HU', '', '', '', '', '', '2027-06-01', '2027-06-02', ''],
            ['', 'B 6004 HU', '', '', '', '', '', '2027-06-01', '2027-06-02', 'mahal'],
        ], self::HEADINGS_UNIT)])->assertStatus(200);

        $this->assertTrue($parse->json('data.ada_kolom_harga'));
        $valid = collect($parse->json('data.baris_valid'))->keyBy('nopol');
        $this->assertEquals(15000000, $valid['B 6001 HU']['harga_kontrak']);
        $this->assertEquals(12500000, $valid['B 6002 HU']['harga_kontrak']);
        $this->assertNull($valid['B 6003 HU']['harga_kontrak']);
        $this->assertCount(1, $parse->json('data.baris_gagal'));
        $this->assertStringContainsString('Harga tidak valid', (string) $parse->json('data.baris_gagal.0.alasan'));

        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 6001 HU', 10000000),
            $this->unit('B 6002 HU', 10000000),
        ])->assertStatus(201)->json('data.id_kontrak_vendor');
        $this->aktifkan($id);

        $tanpaKolom = ['kode_vendor', 'nopol', 'merk', 'jenis', 'jenis_kendaraan', 'kapasitas', 'tahun', 'masa_berlaku_stnk', 'masa_berlaku_kir'];
        $this->postJson("/api/kontrak-vendor/{$id}/timpa-unit", ['file' => $this->makeXlsx([
            ['', 'B 6001 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02'],
            ['', 'B 6002 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02'],
        ], $tanpaKolom)])->assertStatus(200);
        $this->assertEquals(20000000, $this->nilai($id));
        $this->assertSame('aktif', $this->statusKontrak($id));

        $this->postJson("/api/kontrak-vendor/{$id}/timpa-unit", ['file' => $this->makeXlsx([
            ['', 'B 6001 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', 11000000],
            ['', 'B 6002 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', 13000000],
        ], self::HEADINGS_UNIT)])->assertStatus(200);
        $this->assertEquals(24000000, $this->nilai($id));
        $this->assertSame('draft', $this->statusKontrak($id));
    }

    public function test_rincian_approval_dan_pdf_kontrak_menampilkan_harga_unit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 7001 HU', 10000000),
            $this->unit('B 7002 HU', 12000000),
        ])->assertStatus(201)->json('data.id_kontrak_vendor');

        $rincian = app(\App\Modules\Approval\Contracts\RincianReferensiRepositoryInterface::class)
            ->rincian('kontrak_vendor', $id, self::PERUSAHAAN_ID);
        $bagianUnit = collect($rincian['bagian'])->firstWhere('judul', 'Unit');
        $this->assertContains('harga', array_column($bagianUnit['kolom'], 'key'));
        $this->assertEquals(22000000, $bagianUnit['total']['value']);
        $this->assertEquals(10000000, $bagianUnit['baris'][0]['harga']);

        $pdf = $this->get("/api/kontrak-vendor/{$id}/export/pdf");
        $pdf->assertStatus(200);
        $this->assertStringContainsString('pdf', (string) $pdf->headers->get('content-type'));
    }

    public function test_po_terbit_saat_kontrak_aktif_dan_nomornya_tetap_setelah_approval_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 8001 HU', 10000000),
            $this->unit('B 8002 HU', 12000000),
        ], ['pajak_persen' => 11])->assertStatus(201)->assertJsonPath('data.nomor_po', null)->json('data.id_kontrak_vendor');

        $this->postJson("/api/kontrak-vendor/{$id}/po")->assertStatus(422);
        $this->get("/api/kontrak-vendor/{$id}/po/pdf", ['Accept' => 'application/json'])->assertStatus(422);

        $nomor = 'PO-' . now()->format('Ym') . '-0001';
        $this->postJson("/api/kontrak-vendor/{$id}/ajukan-approval")->assertStatus(200)
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.nomor_po', $nomor)
            ->assertJsonPath('data.tanggal_po', now()->toDateString());
        $this->postJson("/api/kontrak-vendor/{$id}/po")->assertStatus(422);

        $data = app(\App\Modules\KontrakVendor\KontrakVendorService::class)->dataPurchaseOrder($id, self::PERUSAHAAN_ID);
        $this->assertCount(2, $data['baris']);
        $this->assertSame('Sewa unit B 8001 HU', $data['baris'][0]['uraian']);
        $this->assertEquals(22000000, $data['subtotal']);
        $this->assertEquals(2420000, $data['pajak']);
        $this->assertEquals(24420000, $data['total']);
        $this->assertSame('PT Vendor Harga', $data['vendor']->nama_vendor);

        $pdf = $this->get("/api/kontrak-vendor/{$id}/po/pdf");
        $pdf->assertStatus(200);
        $this->assertStringContainsString('pdf', (string) $pdf->headers->get('content-type'));
        $this->assertStringContainsString($nomor, (string) $pdf->headers->get('content-disposition'));

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 8001 HU'), ['harga_kontrak' => 10500000])->assertStatus(200);
        $this->assertSame('draft', $this->statusKontrak($id));
        $this->get("/api/kontrak-vendor/{$id}/po/pdf", ['Accept' => 'application/json'])->assertStatus(422);

        $this->postJson("/api/kontrak-vendor/{$id}/ajukan-approval")->assertStatus(200)
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.nomor_po', $nomor);
        $this->assertEquals(22500000, app(\App\Modules\KontrakVendor\KontrakVendorService::class)
            ->dataPurchaseOrder($id, self::PERUSAHAAN_ID)['subtotal']);
    }

    public function test_po_terbit_setelah_disetujui_approver_dan_bisa_diterbitkan_untuk_kontrak_aktif_lama(): void
    {
        $admin = $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $lama = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only',
            'nomor_kontrak' => 'KV-LAMA', 'nilai_kontrak' => 5000000, 'jenis_layanan' => 'Sewa trailer', 'status' => 'aktif',
        ]);
        $this->postJson("/api/kontrak-vendor/{$lama->id_kontrak_vendor}/po")->assertStatus(200)
            ->assertJsonPath('data.nomor_po', 'PO-' . now()->format('Ym') . '-0001');
        $data = app(\App\Modules\KontrakVendor\KontrakVendorService::class)->dataPurchaseOrder((string) $lama->id_kontrak_vendor, self::PERUSAHAAN_ID);
        $this->assertCount(1, $data['baris']);
        $this->assertSame('Sewa trailer sesuai kontrak KV-LAMA', $data['baris'][0]['uraian']);
        $this->assertEquals(5000000, $data['total']);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'kontrak_vendor',
            'nama' => 'Kontrak Vendor', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $admin->id_pengguna, 'dibuat_pada' => now(),
        ]);

        $id = (string) $this->buatKontrak($vendor->id_vendor, [$this->unit('B 9001 HU', 9000000)])->assertStatus(201)->json('data.id_kontrak_vendor');
        $this->postJson("/api/kontrak-vendor/{$id}/ajukan-approval")->assertStatus(200)
            ->assertJsonPath('data.status', 'menunggu_approval')
            ->assertJsonPath('data.nomor_po', null);

        $menunggu = $this->getJson('/api/approval-pengajuan/menunggu-saya')->assertStatus(200)->json('data');
        $this->patchJson("/api/approval-pengajuan/{$menunggu[0]['id_approval']}/keputusan", ['keputusan' => 'setuju'])->assertStatus(200);

        $this->getJson("/api/kontrak-vendor/{$id}")->assertStatus(200)
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.nomor_po', 'PO-' . now()->format('Ym') . '-0002');
    }

    public function test_invoice_vendor_mengambil_nomor_po_dari_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [$this->unit('B 9101 HU', 9000000)])->assertStatus(201)->json('data.id_kontrak_vendor');
        $this->aktifkan($id);
        $nomorPo = (string) DB::table('kontrak_vendor')->where('id_kontrak_vendor', $id)->value('nomor_po');
        $this->assertNotSame('', $nomorPo);

        $invoice = $this->postJson('/api/invoice-vendor', [
            'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $id, 'nomor_invoice' => 'INV-PO-001',
            'tanggal_invoice' => now()->toDateString(), 'dpp' => 1000000, 'no_po' => 'PO-KETIK-SENDIRI',
        ])->assertStatus(201)->assertJsonPath('data.no_po', $nomorPo);

        $this->putJson('/api/invoice-vendor/' . $invoice->json('data.id_invoice_vendor'), ['no_po' => 'LAIN'])->assertStatus(200)
            ->assertJsonPath('data.no_po', $nomorPo);

        $tanpaPo = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only',
            'nomor_kontrak' => 'KV-TANPA-PO', 'nilai_kontrak' => 5000000, 'status' => 'aktif',
        ]);
        $this->postJson('/api/invoice-vendor', [
            'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $tanpaPo->id_kontrak_vendor, 'nomor_invoice' => 'INV-PO-002',
            'tanggal_invoice' => now()->toDateString(), 'dpp' => 1000000, 'no_po' => 'PO-MANUAL-7',
        ])->assertStatus(201)->assertJsonPath('data.no_po', 'PO-MANUAL-7');

        $daftar = collect($this->getJson('/api/kontrak-vendor?limit=50&id_vendor=' . $vendor->id_vendor)->assertStatus(200)->json('data'));
        $this->assertSame($nomorPo, $daftar->firstWhere('id_kontrak_vendor', $id)['nomor_po']);
    }

    private function pasangApproval(string $idApprover): void
    {
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'kontrak_vendor',
            'nama' => 'Kontrak Vendor', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
        ]);
    }

    private function statusPengajuan(string $idKontrak): array
    {
        return DB::table('approval_pengajuan')->where('id_referensi', $idKontrak)->orderBy('dibuat_pada')->pluck('status')->all();
    }

    public function test_status_dari_klien_diabaikan_sehingga_approval_dan_po_tidak_bisa_dilewati(): void
    {
        $admin = $this->actingAsRole('SUPERADMIN');
        $this->pasangApproval((string) $admin->id_pengguna);
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [$this->unit('B 1101 HU', 9000000)])->assertStatus(201)->json('data.id_kontrak_vendor');

        $this->putJson("/api/kontrak-vendor/{$id}", ['status' => 'aktif', 'jenis_layanan' => 'Sewa'])->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.jenis_layanan', 'Sewa')
            ->assertJsonPath('data.nomor_po', null);
        $this->postJson("/api/kontrak-vendor/{$id}/po")->assertStatus(422);
        $this->assertSame([], $this->statusPengajuan($id));

        $this->postJson("/api/kontrak-vendor/{$id}/ajukan-approval")->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');
        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 1101 HU'), ['harga_kontrak' => 9500000])->assertStatus(200);
        $this->assertSame('draft', $this->statusKontrak($id));
        $this->assertSame(['dibatalkan'], $this->statusPengajuan($id));

        $this->postJson("/api/kontrak-vendor/{$id}/ajukan-approval")->assertStatus(200);
        $menunggu = $this->getJson('/api/approval-pengajuan/menunggu-saya')->assertStatus(200)->json('data');
        $this->patchJson("/api/approval-pengajuan/{$menunggu[0]['id_approval']}/keputusan", ['keputusan' => 'setuju'])->assertStatus(200);
        $this->assertSame('aktif', $this->statusKontrak($id));
        $po = DB::table('kontrak_vendor')->where('id_kontrak_vendor', $id)->first(['nomor_po', 'tanggal_po']);
        $this->assertNotNull($po->nomor_po);

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 1101 HU'), ['harga_kontrak' => 9900000])->assertStatus(200);
        $this->assertSame('draft', $this->statusKontrak($id));
        $this->assertSame(['dibatalkan', 'dibatalkan'], $this->statusPengajuan($id));

        $this->putJson("/api/kontrak-vendor/{$id}", ['status' => 'aktif', 'nomor_kontrak' => 'KV-TAB-BASI'])->assertStatus(200)
            ->assertJsonPath('data.status', 'draft');
        $this->assertEquals(9900000, $this->nilai($id));
        $sesudah = DB::table('kontrak_vendor')->where('id_kontrak_vendor', $id)->first(['nomor_po', 'tanggal_po']);
        $this->assertSame($po->nomor_po, $sesudah->nomor_po);
        $this->assertSame($po->tanggal_po, $sesudah->tanggal_po);
    }

    public function test_baris_excel_gagal_tidak_menghapus_unit_dan_sel_harga_kosong_tidak_menghapus_harga(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 1201 HU', 10000000),
            $this->unit('B 1202 HU', 12000000),
        ])->assertStatus(201)->json('data.id_kontrak_vendor');
        $this->aktifkan($id);

        $hasil = $this->postJson("/api/kontrak-vendor/{$id}/timpa-unit", ['file' => $this->makeXlsx([
            ['', 'B 1201 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', 10000000],
            ['', 'B 1202 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', 'dua belas juta'],
        ], self::HEADINGS_UNIT)])->assertStatus(200);
        $this->assertSame(0, $hasil->json('data.dihapus'));
        $this->assertCount(1, $hasil->json('data.gagal'));
        $this->assertNotSame('', $this->idUnit('B 1202 HU'));
        $this->assertEquals(22000000, $this->nilai($id));
        $this->assertSame('aktif', $this->statusKontrak($id));

        $this->postJson("/api/kontrak-vendor/{$id}/timpa-unit", ['file' => $this->makeXlsx([
            ['', 'B 1201 HU', 'Hino Baru', '', '', '', '', '2027-06-01', '2027-06-02', ''],
            ['', 'B 1202 HU', 'Hino Baru', '', '', '', '', '2027-06-01', '2027-06-02', ''],
        ], self::HEADINGS_UNIT)])->assertStatus(200)->assertJsonPath('data.diperbarui', 2);
        $this->assertEquals(12000000, ArmadaVendorModel::where('nopol', 'B 1202 HU')->value('harga_kontrak'));
        $this->assertSame('Hino Baru', ArmadaVendorModel::where('nopol', 'B 1202 HU')->value('merk'));
        $this->assertEquals(22000000, $this->nilai($id));
        $this->assertSame('aktif', $this->statusKontrak($id));

        $parse = $this->postJson('/api/kontrak-vendor/parse-unit', ['file' => $this->makeXlsx([
            ['', 'B 1299 HU', '', '', '', '', '', '2027-06-01', '2027-06-02', 'mahal'],
        ], self::HEADINGS_UNIT)])->assertStatus(200);
        $this->assertSame('B 1299 HU', $parse->json('data.baris_gagal.0.nopol'));
    }

    public function test_timpa_pasangan_membaca_harga_dan_memicu_approval_ulang_bila_harga_berubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 1301 HU', 10000000),
            $this->unit('B 1302 HU', 12000000),
        ], ['mekanisme' => 'unit_driver'])->assertStatus(201)->json('data.id_kontrak_vendor');
        $this->aktifkan($id);

        $kepala = ['nopol', 'merk', 'jenis', 'jenis_kendaraan', 'kapasitas', 'tahun', 'masa_berlaku_stnk', 'masa_berlaku_kir', 'nama_driver', 'telepon_driver', 'no_sim_driver', 'harga'];
        $this->postJson("/api/kontrak-vendor/{$id}/timpa-pasangan", ['file' => $this->makeXlsx([
            ['B 1301 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', '', '', '', 10000000],
            ['B 1302 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', '', '', '', ''],
        ], $kepala)])->assertStatus(200);
        $this->assertSame('aktif', $this->statusKontrak($id));
        $this->assertEquals(22000000, $this->nilai($id));

        $this->postJson("/api/kontrak-vendor/{$id}/timpa-pasangan", ['file' => $this->makeXlsx([
            ['B 1301 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', '', '', '', 'Rp 11.000.000,-'],
            ['B 1302 HU', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', '', '', '', '13,000,000'],
        ], $kepala)])->assertStatus(200);
        $this->assertSame('draft', $this->statusKontrak($id));
        $this->assertEquals(24000000, $this->nilai($id));
    }

    public function test_pindah_unit_antar_kontrak_menghitung_ulang_keduanya_dan_mengosongkan_harga(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $asal = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 1401 HU', 10000000),
            $this->unit('B 1402 HU', 12000000),
        ])->assertStatus(201)->json('data.id_kontrak_vendor');
        $tujuan = (string) $this->buatKontrak($vendor->id_vendor, [$this->unit('B 1403 HU', 7000000)])->assertStatus(201)->json('data.id_kontrak_vendor');

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 1402 HU'), ['id_kontrak_vendor' => $tujuan])->assertStatus(200)
            ->assertJsonPath('data.harga_kontrak', null);
        $this->assertEquals(10000000, $this->nilai($asal));
        $this->assertEquals(7000000, $this->nilai($tujuan));

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 1402 HU'), ['harga_kontrak' => 12500000])->assertStatus(200);
        $this->assertEquals(19500000, $this->nilai($tujuan));
    }

    public function test_rate_diperiksa_ulang_saat_ajukan_dan_po_tetap_konsisten_untuk_kontrak_selesai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $id = (string) $this->buatKontrak($vendor->id_vendor, [
            $this->unit('B 1501 HU', 10000000),
            $this->unit('B 1502 HU', 12000000),
        ], ['rate' => 15000000])->assertStatus(201)->json('data.id_kontrak_vendor');

        $this->deleteJson('/api/armada-vendor/' . $this->idUnit('B 1502 HU'))->assertStatus(200);
        $this->assertEquals(10000000, $this->nilai($id));
        $tolak = $this->postJson("/api/kontrak-vendor/{$id}/ajukan-approval")->assertStatus(422);
        $this->assertStringContainsString('Rate tidak boleh lebih besar', (string) $tolak->json('message'));

        $this->putJson("/api/kontrak-vendor/{$id}", ['rate' => 9000000])->assertStatus(200);
        $this->aktifkan($id);
        $this->patchJson("/api/kontrak-vendor/{$id}/selesai")->assertStatus(200);

        $this->putJson('/api/armada-vendor/' . $this->idUnit('B 1501 HU'), ['harga_kontrak' => 50000000])->assertStatus(200);
        $this->assertEquals(10000000, $this->nilai($id));
        $data = app(\App\Modules\KontrakVendor\KontrakVendorService::class)->dataPurchaseOrder($id, self::PERUSAHAAN_ID);
        $this->assertCount(1, $data['baris']);
        $this->assertEquals(10000000, $data['baris'][0]['jumlah']);
        $this->assertEquals(array_sum(array_column($data['baris'], 'jumlah')), $data['subtotal']);

        $kosong = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only',
            'nomor_kontrak' => 'KV-NOL', 'nilai_kontrak' => 0, 'status' => 'aktif', 'nomor_po' => 'PO-NOL', 'tanggal_po' => now()->toDateString(),
        ]);
        $this->get("/api/kontrak-vendor/{$kosong->id_kontrak_vendor}/po/pdf", ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_po_tidak_bisa_diakses_perusahaan_lain_dan_invoice_lama_ikut_terisi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $lama = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only',
            'nomor_kontrak' => 'KV-LAMA-2', 'nilai_kontrak' => 8000000, 'status' => 'aktif',
        ]);
        $baru = (string) $this->buatKontrak($vendor->id_vendor, [$this->unit('B 1601 HU', 9000000)])->assertStatus(201)->json('data.id_kontrak_vendor');
        $this->aktifkan($baru);
        $poBaru = (string) DB::table('kontrak_vendor')->where('id_kontrak_vendor', $baru)->value('nomor_po');

        $kosong = (string) $this->postJson('/api/invoice-vendor', [
            'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $lama->id_kontrak_vendor, 'nomor_invoice' => 'INV-BF-001',
            'tanggal_invoice' => now()->toDateString(), 'dpp' => 1000000,
        ])->assertStatus(201)->assertJsonPath('data.no_po', null)->json('data.id_invoice_vendor');
        $manual = (string) $this->postJson('/api/invoice-vendor', [
            'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $lama->id_kontrak_vendor, 'nomor_invoice' => 'INV-BF-002',
            'tanggal_invoice' => now()->toDateString(), 'dpp' => 1000000, 'no_po' => 'PO-VENDOR-77',
        ])->assertStatus(201)->json('data.id_invoice_vendor');

        $poLama = (string) $this->postJson("/api/kontrak-vendor/{$lama->id_kontrak_vendor}/po")->assertStatus(200)->json('data.nomor_po');
        $this->assertSame($poLama, DB::table('invoice_vendor')->where('id_invoice_vendor', $kosong)->value('no_po'));
        $this->assertSame('PO-VENDOR-77', DB::table('invoice_vendor')->where('id_invoice_vendor', $manual)->value('no_po'));

        $this->putJson("/api/invoice-vendor/{$manual}", ['id_kontrak_vendor' => $baru])->assertStatus(200)
            ->assertJsonPath('data.no_po', $poBaru);
        $this->putJson("/api/invoice-vendor/{$kosong}", ['keterangan' => 'Catatan saja'])->assertStatus(200)
            ->assertJsonPath('data.no_po', $poLama);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $orangLain = \App\Models\Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'u_' . Str::random(8), 'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($orangLain, ['*']);
        $this->get("/api/kontrak-vendor/{$baru}/po/pdf", ['Accept' => 'application/json'])->assertStatus(404);
        $this->postJson("/api/kontrak-vendor/{$baru}/po")->assertStatus(404);
    }

    public function test_parse_harga_menerima_format_umum_dan_menolak_yang_tidak_wajar(): void
    {
        $harga = static fn (?string $teks) => \App\Support\ExcelCellHelper::parseHarga($teks);

        foreach (['15000000', 'Rp15.000.000,-', 'Rp 15.000.000', '15,000,000', '15000000,00', '15 000 000', 'IDR 15000000', '15.000.000,00'] as $teks) {
            $this->assertEquals(15000000, $harga($teks), $teks);
        }
        $this->assertEquals(15000001, $harga('15.000.000,50'));
        $this->assertEquals(150125, $harga('150.125'));
        $this->assertNull($harga(null));
        $this->assertNull($harga(''));
        $this->assertNull($harga('0'));
        foreach (['mahal', '-5000000', '15.5', '999', '1e3x'] as $teks) {
            $this->assertFalse($harga($teks), $teks);
        }

        $this->assertNotContains('harga', (new \App\Modules\ArmadaVendor\Exports\ArmadaVendorTemplateExport())->headings());
        $this->assertContains('harga', (new \App\Modules\ArmadaVendor\Exports\ArmadaVendorTemplateExport(true))->headings());
        $this->actingAsRole('SUPERADMIN');
        $this->get('/api/kontrak-vendor/template-unit')->assertStatus(200);
    }
}
