<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Import armada via template Excel — lihat
 * docs/superpowers/specs/2026-07-16-import-armada-excel-design.md poin Backend #4.
 */
class ArmadaImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeXlsxUploadedFile(
        array $rows,
        array $headings = ['nopol', 'merk', 'model', 'tahun', 'status'],
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

    public function test_download_template_mengembalikan_200_dan_content_type_xlsx(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->get('/api/v1/armada/import/template');

        $res->assertStatus(200);
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_tiga_baris_valid_berhasil_semua_dan_masuk_db(): void
    {
        $this->actingAsRole('ADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['B 1111 AAA', 'Toyota', 'Avanza', 2020, 'tersedia'],
            ['B 2222 BBB', 'Honda', 'Brio', 2021, 'digunakan'],
            ['B 3333 CCC', 'Suzuki', 'Ertiga', 2022, ''],
        ]);

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 3)
            ->assertJsonPath('data.gagal', []);

        $this->assertDatabaseHas('armada', [
            'nopol'  => 'B 1111 AAA',
            'status' => 'tersedia',
        ]);
        $this->assertDatabaseHas('armada', [
            'nopol'  => 'B 2222 BBB',
            'status' => 'digunakan',
        ]);
        // status kosong -> default tersedia
        $this->assertDatabaseHas('armada', [
            'nopol'  => 'B 3333 CCC',
            'status' => 'tersedia',
        ]);
    }

    public function test_import_campuran_hitungan_dan_alasan_akurat(): void
    {
        $this->actingAsRole('ADMIN');

        ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 9999 SUD',
            'status'        => 'tersedia',
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['B 5555 VLD', 'Toyota', 'Avanza', 2020, 'tersedia'],   // baris 2: valid
            ['', 'Honda', 'Brio', 2021, 'tersedia'],                 // baris 3: tanpa nopol
            ['B 6666 STS', 'Suzuki', 'Ertiga', 2021, 'salah'],       // baris 4: status salah
            ['B 9999 SUD', 'Isuzu', 'Elf', 2019, 'tersedia'],        // baris 5: duplikat DB
            ['B 7777 DUP', 'Daihatsu', 'Xenia', 2018, 'tersedia'],   // baris 6: duplikat antar-baris
            ['B 7777 DUP', 'Daihatsu', 'Xenia', 2018, 'tersedia'],   // baris 7: duplikat antar-baris
        ]);

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);

        $gagal = $res->json('data.gagal');
        $this->assertCount(5, $gagal);

        $byBaris = collect($gagal)->keyBy('baris');

        $this->assertSame('Nopol wajib diisi', $byBaris[3]['alasan']);
        $this->assertSame('Status tidak valid', $byBaris[4]['alasan']);
        $this->assertSame('Nopol sudah terdaftar', $byBaris[5]['alasan']);
        $this->assertSame('Nopol duplikat di dalam file', $byBaris[6]['alasan']);
        $this->assertSame('Nopol duplikat di dalam file', $byBaris[7]['alasan']);
        $this->assertSame('B 7777 DUP', $byBaris[6]['nopol']);

        $this->assertDatabaseHas('armada', ['nopol' => 'B 5555 VLD']);
        $this->assertDatabaseMissing('armada', ['nopol' => 'B 6666 STS']);
        $this->assertDatabaseMissing('armada', ['nopol' => 'B 7777 DUP']);
    }

    public function test_import_tahun_tidak_valid_masuk_gagal(): void
    {
        $this->actingAsRole('ADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['B 8888 THN', 'Toyota', 'Avanza', 1800, 'tersedia'],
        ]);

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $gagal = $res->json('data.gagal');
        $this->assertCount(1, $gagal);
        $this->assertSame('Tahun tidak valid', $gagal[0]['alasan']);
        $this->assertDatabaseMissing('armada', ['nopol' => 'B 8888 THN']);
    }

    public function test_import_baris_kosong_total_dilewati_tanpa_dihitung(): void
    {
        $this->actingAsRole('ADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['B 4444 OKE', 'Toyota', 'Avanza', 2020, 'tersedia'],
            ['', '', '', '', ''],
        ]);

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1)
            ->assertJsonPath('data.gagal', []);
    }

    public function test_import_file_bukan_excel_mengembalikan_422(): void
    {
        $this->actingAsRole('ADMIN');

        $file = UploadedFile::fake()->create('data.txt', 10, 'text/plain');

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(422);
    }

    public function test_import_template_baru_dengan_kolom_detail_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $file = $this->makeXlsxUploadedFile(
            [[
                'B 1010 DTL', 'Hino', 'Dutro', 2023, 'tersedia',
                'MHFXW42G5N0000010', '1TR-0000010', 'Putih', 'solar', 5000,
                '2024-01-10', 425000000, 'baru', 'Unit baru',
            ]],
            [
                'nopol', 'merk', 'model', 'tahun', 'status',
                'nomor_rangka', 'nomor_mesin', 'warna', 'jenis_bahan_bakar', 'kapasitas_muatan_kg',
                'tanggal_beli', 'harga_beli', 'kondisi_beli', 'keterangan',
            ]
        );

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1)
            ->assertJsonPath('data.gagal', []);

        $this->assertDatabaseHas('armada', [
            'nopol'               => 'B 1010 DTL',
            'nomor_rangka'        => 'MHFXW42G5N0000010',
            'warna'               => 'Putih',
            'jenis_bahan_bakar'   => 'solar',
            'kapasitas_muatan_kg' => 5000,
            'kondisi_beli'        => 'baru',
            'keterangan'          => 'Unit baru',
        ]);
    }

    public function test_import_template_lama_lima_kolom_tetap_valid(): void
    {
        $this->actingAsRole('ADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['B 2020 LMA', 'Toyota', 'Avanza', 2020, 'tersedia'],
        ]);

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1)
            ->assertJsonPath('data.gagal', []);
    }

    public function test_import_jenis_bahan_bakar_salah_gagal_dengan_alasan(): void
    {
        $this->actingAsRole('ADMIN');

        $file = $this->makeXlsxUploadedFile(
            [['B 3030 BBM', 'Hino', null, null, null, null, null, null, 'nuklir', null, null, null, null, null]],
            [
                'nopol', 'merk', 'model', 'tahun', 'status',
                'nomor_rangka', 'nomor_mesin', 'warna', 'jenis_bahan_bakar', 'kapasitas_muatan_kg',
                'tanggal_beli', 'harga_beli', 'kondisi_beli', 'keterangan',
            ]
        );

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 0)
            ->assertJsonPath('data.gagal.0.alasan', 'Jenis bahan bakar tidak valid');
    }

    public function test_import_nomor_rangka_duplikat_db_gagal_dengan_alasan(): void
    {
        $this->actingAsRole('ADMIN');

        ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 4040 ADA',
            'nomor_rangka'  => 'MHFXW42G5N0000040',
        ]);

        $file = $this->makeXlsxUploadedFile(
            [['B 5050 BRU', 'Hino', null, null, null, 'MHFXW42G5N0000040', null, null, null, null, null, null, null, null]],
            [
                'nopol', 'merk', 'model', 'tahun', 'status',
                'nomor_rangka', 'nomor_mesin', 'warna', 'jenis_bahan_bakar', 'kapasitas_muatan_kg',
                'tanggal_beli', 'harga_beli', 'kondisi_beli', 'keterangan',
            ]
        );

        $res = $this->postJson('/api/v1/armada/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 0)
            ->assertJsonPath('data.gagal.0.alasan', 'Nomor rangka sudah terdaftar');
    }
}
