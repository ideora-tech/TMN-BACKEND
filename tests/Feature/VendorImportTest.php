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

class VendorImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = [
        'kode_vendor', 'nama_vendor', 'jenis_vendor', 'pic_nama',
        'email', 'telepon', 'alamat', 'npwp', 'tanggal_bergabung',
    ];

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

        $res = $this->get('/api/v1/vendor/import/template');

        $res->assertStatus(200);
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_baris_valid_masuk_dan_baris_bermasalah_dilaporkan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        DB::table('vendor')->insert([
            'id_vendor' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => 'VDR-ADA', 'nama_vendor' => 'Vendor Lama', 'dibuat_pada' => now(),
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['VDR-100', 'PT Vendor Baru', 'transportir', 'Budi', 'v@x.com', '0812', 'Bekasi', '01.234', '2026-01-15'],
            ['VDR-ADA', 'Vendor Tabrakan', '', '', '', '', '', '', ''],
            ['', 'Tanpa Kode', '', '', '', '', '', '', ''],
            ['VDR-101', '', '', '', '', '', '', '', ''],
            ['VDR-102', 'Email Rusak', '', '', 'bukan-email', '', '', '', ''],
            ['VDR-103', 'Tanggal Rusak', '', '', '', '', '', '', 'bukan-tanggal'],
        ]);

        $res = $this->postJson('/api/v1/vendor/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $this->assertCount(5, $res->json('data.gagal'));

        $this->assertDatabaseHas('vendor', [
            'kode_vendor' => 'VDR-100', 'nama_vendor' => 'PT Vendor Baru',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);

        $alasan = collect($res->json('data.gagal'))->pluck('alasan');
        $this->assertTrue($alasan->contains('Kode vendor sudah terdaftar'));
        $this->assertTrue($alasan->contains('Kode vendor wajib diisi'));
        $this->assertTrue($alasan->contains('Nama vendor wajib diisi'));
        $this->assertTrue($alasan->contains('Email tidak valid'));
        $this->assertTrue($alasan->contains('Tanggal bergabung tidak valid (format YYYY-MM-DD)'));
    }

    public function test_import_kode_duplikat_dalam_file_ditolak_semua(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['VDR-200', 'Vendor A', '', '', '', '', '', '', ''],
            ['VDR-200', 'Vendor B', '', '', '', '', '', '', ''],
        ]);

        $res = $this->postJson('/api/v1/vendor/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $this->assertCount(2, $res->json('data.gagal'));
        $this->assertSame(0, DB::table('vendor')->where('kode_vendor', 'VDR-200')->count());
    }

    public function test_import_file_bukan_excel_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = UploadedFile::fake()->create('data.pdf', 10, 'application/pdf');

        $this->postJson('/api/v1/vendor/import', ['file' => $file])->assertStatus(422);
    }
}
