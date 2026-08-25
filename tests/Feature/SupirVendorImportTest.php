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

class SupirVendorImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = ['kode_vendor', 'nama', 'telepon', 'no_sim', 'masa_berlaku_sim'];

    private function makeVendor(string $kode = 'VDR-001'): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => $kode, 'nama_vendor' => 'Vendor Supir Test', 'dibuat_pada' => now(),
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

        $res = $this->get('/api/supir-vendor/import/template');

        $res->assertStatus(200);
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_baris_valid_masuk_dan_bermasalah_dilaporkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idVendor = $this->makeVendor('VDR-001');

        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => (string) Str::uuid(), 'id_vendor' => $idVendor,
            'nama' => 'Supir Lama', 'no_sim' => 'SIM-ADA', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['VDR-001', 'Joko Susilo', '0812', 'SIM-100', '2027-06-30'],
            ['VDR-001', '', '', '', ''],
            ['VDR-XXX', 'Vendor Salah', '', '', ''],
            ['VDR-001', 'Sim Tabrakan', '', 'SIM-ADA', ''],
            ['VDR-001', 'Tanggal Rusak', '', '', 'bukan-tanggal'],
            ['VDR-001', 'Dup A', '', 'SIM-DUP', ''],
            ['VDR-001', 'Dup B', '', 'SIM-DUP', ''],
        ]);

        $res = $this->postJson('/api/supir-vendor/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $this->assertCount(6, $res->json('data.gagal'));

        $this->assertDatabaseHas('supir_vendor', [
            'nama' => 'Joko Susilo', 'id_vendor' => $idVendor, 'no_sim' => 'SIM-100',
        ]);

        $alasan = collect($res->json('data.gagal'))->pluck('alasan');
        $this->assertTrue($alasan->contains('Nama wajib diisi'));
        $this->assertTrue($alasan->contains("Vendor dengan kode 'VDR-XXX' tidak ditemukan"));
        $this->assertTrue($alasan->contains('No SIM sudah terdaftar'));
        $this->assertTrue($alasan->contains('Masa berlaku SIM tidak valid (format YYYY-MM-DD)'));
        $this->assertTrue($alasan->contains('No SIM duplikat di dalam file'));
    }
}
