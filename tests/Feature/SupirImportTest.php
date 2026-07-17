<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Import supir via template Excel (+match armada by nopol) — lihat
 * docs/superpowers/specs/2026-07-16-import-supir-excel-design.md poin Backend #4.
 */
class SupirImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeXlsxUploadedFile(
        array $rows,
        array $headings = ['nama', 'no_sim', 'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status', 'nopol_armada_default'],
        string $filename = 'import.xlsx'
    ): UploadedFile {
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

        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function makeArmada(string $nopol): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'status'        => 'tersedia',
        ]);
    }

    public function test_download_template_mengembalikan_200_dan_content_type_xlsx(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->get('/api/v1/supir/import/template');

        $res->assertStatus(200);
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_dua_baris_valid_berhasil_dan_nopol_match_mengisi_id_armada_default(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('B 1234 XYZ');

        $file = $this->makeXlsxUploadedFile([
            ['Budi Santoso', '1234567890', 'B2', '2027-01-31', '081234567890', 'aktif', 'B 1234 XYZ'],
            ['Andi Wijaya', '9876543210', 'A', '2026-05-01', '081298765432', 'aktif', ''],
        ]);

        $res = $this->postJson('/api/v1/supir/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 2)
            ->assertJsonPath('data.gagal', []);

        $this->assertDatabaseHas('supir', [
            'nama'               => 'Budi Santoso',
            'no_sim'             => '1234567890',
            'id_armada_default'  => $armada->id_armada,
            'tgl_kadaluarsa_sim' => '2027-01-31',
        ]);
        $this->assertDatabaseHas('supir', [
            'nama'              => 'Andi Wijaya',
            'no_sim'            => '9876543210',
            'id_armada_default' => null,
        ]);
    }

    public function test_import_campuran_hitungan_dan_alasan_akurat(): void
    {
        $this->actingAsRole('ADMIN');

        DB::table('supir')->insert([
            'id_supir'      => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Sudah Ada',
            'no_sim'        => 'SIM-EXIST',
            'dibuat_pada'   => now(),
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['Valid Satu', 'SIM-VALID1', '', '', '', 'aktif', ''],           // baris 2: valid
            ['', 'SIM-KOSONG', '', '', '', 'aktif', ''],                     // baris 3: tanpa nama
            ['Duplikat DB', 'SIM-EXIST', '', '', '', 'aktif', ''],           // baris 4: no_sim duplikat DB
            ['Nopol Salah', 'SIM-NOPOL', '', '', '', 'aktif', 'B 0000 ZZZ'], // baris 5: nopol tak dikenal
            ['Status Salah', 'SIM-STATUS', '', '', '', 'salah', ''],         // baris 6: status salah
        ]);

        $res = $this->postJson('/api/v1/supir/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);

        $gagal = $res->json('data.gagal');
        $this->assertCount(4, $gagal);

        $byBaris = collect($gagal)->keyBy('baris');

        $this->assertSame('Nama wajib diisi', $byBaris[3]['alasan']);
        $this->assertSame('No SIM sudah terdaftar', $byBaris[4]['alasan']);
        $this->assertSame('Nopol armada tidak ditemukan', $byBaris[5]['alasan']);
        $this->assertSame('Status tidak valid', $byBaris[6]['alasan']);

        $this->assertDatabaseHas('supir', ['nama' => 'Valid Satu']);
        $this->assertDatabaseMissing('supir', ['nama' => 'Status Salah']);
        $this->assertDatabaseMissing('supir', ['nama' => 'Nopol Salah']);
    }

    public function test_import_no_sim_duplikat_di_dalam_file_menandai_kedua_baris(): void
    {
        $this->actingAsRole('ADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['Satu', 'SIM-DUP', '', '', '', 'aktif', ''],
            ['Dua', 'SIM-DUP', '', '', '', 'aktif', ''],
        ]);

        $res = $this->postJson('/api/v1/supir/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $gagal = $res->json('data.gagal');
        $this->assertCount(2, $gagal);
        $this->assertSame('No SIM duplikat di dalam file', $gagal[0]['alasan']);
        $this->assertSame('No SIM duplikat di dalam file', $gagal[1]['alasan']);
    }

    public function test_import_baris_kosong_total_dilewati_tanpa_dihitung(): void
    {
        $this->actingAsRole('ADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['Oke Saja', 'SIM-OKE', '', '', '', 'aktif', ''],
            ['', '', '', '', '', '', ''],
        ]);

        $res = $this->postJson('/api/v1/supir/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1)
            ->assertJsonPath('data.gagal', []);
    }

    public function test_import_file_bukan_excel_mengembalikan_422(): void
    {
        $this->actingAsRole('ADMIN');

        $file = UploadedFile::fake()->create('data.txt', 10, 'text/plain');

        $res = $this->postJson('/api/v1/supir/import', ['file' => $file]);

        $res->assertStatus(422);
    }

    public function test_import_nopol_yang_sudah_dipegang_supir_lain_ditolak_dengan_nama_pemegang(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('B 5555 QQQ');

        DB::table('supir')->insert([
            'id_supir'          => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nama'              => 'Pemegang Lama',
            'no_sim'            => 'SIM-PEMEGANG',
            'id_armada_default' => $armada->id_armada,
            'dibuat_pada'       => now(),
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['Rebut Armada', 'SIM-REBUT', '', '', '', 'aktif', 'B 5555 QQQ'],
        ]);

        $res = $this->postJson('/api/v1/supir/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);

        $gagal = $res->json('data.gagal');
        $this->assertCount(1, $gagal);
        $this->assertStringContainsString('Pemegang Lama', $gagal[0]['alasan']);

        $this->assertDatabaseMissing('supir', ['nama' => 'Rebut Armada']);
    }

    public function test_import_dua_baris_nopol_sama_di_dalam_file_menandai_kedua_baris(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeArmada('B 7777 RRR');

        $file = $this->makeXlsxUploadedFile([
            ['Satu', 'SIM-NOPOL1', '', '', '', 'aktif', 'B 7777 RRR'],
            ['Dua', 'SIM-NOPOL2', '', '', '', 'aktif', 'B 7777 RRR'],
        ]);

        $res = $this->postJson('/api/v1/supir/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $gagal = $res->json('data.gagal');
        $this->assertCount(2, $gagal);
        $this->assertSame('Nopol armada duplikat di dalam file', $gagal[0]['alasan']);
        $this->assertSame('Nopol armada duplikat di dalam file', $gagal[1]['alasan']);
    }
}
