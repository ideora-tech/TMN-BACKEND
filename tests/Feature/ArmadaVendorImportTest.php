<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ArmadaVendorImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = [
        'kode_vendor', 'nopol', 'merk', 'jenis', 'jenis_kendaraan',
        'kapasitas', 'tahun', 'masa_berlaku_stnk', 'masa_berlaku_kir',
    ];

    private function makeVendor(string $kode = 'VDR-001'): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => $kode, 'nama_vendor' => 'Vendor Import Test', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $nama = 'Tronton'): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeXlsxUploadedFile(array $rows, array $headings = self::HEADINGS): UploadedFile
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

        $contents = Excel::raw($export, ExcelWriterType::XLSX);
        $path = sys_get_temp_dir() . '/' . Str::random(10) . '.xlsx';
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_download_template_mengembalikan_xlsx(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->get('/api/v1/armada-vendor/import/template');

        $res->assertStatus(200);
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_resolusi_kode_vendor_dan_jenis_kendaraan_by_nama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idVendor = $this->makeVendor('VDR-001');
        $idJenis  = $this->makeJenisKendaraan('Tronton');

        $file = $this->makeXlsxUploadedFile([
            ['VDR-001', 'B 1111 VA', 'Hino', 'Dump Truck', 'Tronton', '20 ton', 2021, '2027-03-01', '2026-12-15'],
            ['vdr-001 ', 'B 2222 VB', 'Isuzu', '', 'tronton', '', '', '', ''],
            ['VDR-XXX', 'B 3333 VC', '', '', '', '', '', '', ''],
            ['VDR-001', 'B 4444 VD', '', '', 'Jenis Tak Ada', '', '', '', ''],
        ]);

        $res = $this->postJson('/api/v1/armada-vendor/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 2);
        $this->assertCount(2, $res->json('data.gagal'));

        $this->assertDatabaseHas('armada_vendor', [
            'nopol' => 'B 1111 VA', 'id_vendor' => $idVendor, 'id_jenis_kendaraan' => $idJenis, 'tahun' => 2021,
        ]);
        // Kode vendor & nama jenis case-insensitive + trim.
        $this->assertDatabaseHas('armada_vendor', [
            'nopol' => 'B 2222 VB', 'id_vendor' => $idVendor, 'id_jenis_kendaraan' => $idJenis,
        ]);

        $alasan = collect($res->json('data.gagal'))->pluck('alasan');
        $this->assertTrue($alasan->contains("Vendor dengan kode 'VDR-XXX' tidak ditemukan"));
        $this->assertTrue($alasan->contains("Jenis kendaraan 'Jenis Tak Ada' tidak ditemukan di master"));
    }

    public function test_import_nopol_sudah_terdaftar_dan_duplikat_dalam_file_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idVendor = $this->makeVendor('VDR-001');
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => (string) Str::uuid(), 'id_vendor' => $idVendor,
            'nopol' => 'B 9999 ADA', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['VDR-001', 'B 9999 ADA', '', '', '', '', '', '', ''],
            ['VDR-001', 'B 8888 DUP', '', '', '', '', '', '', ''],
            ['VDR-001', 'B 8888 DUP', '', '', '', '', '', '', ''],
        ]);

        $res = $this->postJson('/api/v1/armada-vendor/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $this->assertCount(3, $res->json('data.gagal'));

        $alasan = collect($res->json('data.gagal'))->pluck('alasan');
        $this->assertTrue($alasan->contains('Nopol sudah terdaftar'));
        $this->assertTrue($alasan->contains('Nopol duplikat di dalam file'));
    }
}
