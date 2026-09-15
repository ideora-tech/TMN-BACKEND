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

class SparepartImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = ['kode', 'nama', 'serial_number', 'merek', 'tahun', 'kategori', 'satuan', 'harga_standar', 'stok_awal'];

    private function makeXlsxUploadedFile(
        array $rows,
        array $headings = self::HEADINGS,
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

    private function makeKategori(string $nama, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('kategori_sparepart')->insert([
            'id_kategori_sparepart' => $id,
            'id_perusahaan'         => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'                  => $nama,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);
        return $id;
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    private function makeSparepart(string $kode, string $nama = 'Sudah Ada'): void
    {
        DB::table('sparepart')->insert([
            'id_sparepart'  => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => $kode,
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => 0,
            'stok'          => 0,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
    }

    public function test_download_template_mengembalikan_200_dan_content_type_xlsx(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->get('/api/sparepart/import/template');

        $res->assertStatus(200);
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_dua_baris_valid_mengisi_kategori_stok_awal_dan_mutasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKategori = $this->makeKategori('Filter');

        $file = $this->makeXlsxUploadedFile([
            ['SP-001', 'Filter Oli', 'FO-12345', 'Sakura', 2024, 'filter', 'pcs', 85000, 10],
            ['SP-002', 'Kampas Rem', 'KR-555', 'Bendix', '', '', 'set', '', ''],
        ]);

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 2)
            ->assertJsonPath('data.gagal', []);

        $this->assertDatabaseHas('sparepart', [
            'id_perusahaan'         => self::PERUSAHAAN_ID,
            'kode'                  => 'SP-001',
            'nama'                  => 'Filter Oli',
            'serial_number'         => 'FO-12345',
            'merek'                 => 'Sakura',
            'tahun'                 => 2024,
            'id_kategori_sparepart' => $idKategori,
            'satuan'                => 'pcs',
            'stok'                  => 10,
            'aktif'                 => 1,
        ]);

        $sp1 = DB::table('sparepart')->where('kode', 'SP-001')->first();
        $this->assertSame(85000.0, (float) $sp1->harga_standar);

        $mutasi = DB::table('sparepart_mutasi')->where('id_sparepart', $sp1->id_sparepart)->get();
        $this->assertCount(1, $mutasi);
        $this->assertSame('penyesuaian', $mutasi[0]->jenis);
        $this->assertSame(10, (int) $mutasi[0]->qty);
        $this->assertSame(85000.0, (float) $mutasi[0]->harga);
        $this->assertSame('Saldo awal (import Excel)', $mutasi[0]->keterangan);
        $this->assertSame(now()->toDateString(), $mutasi[0]->tanggal);

        $this->assertDatabaseHas('sparepart', [
            'kode'                  => 'SP-002',
            'serial_number'         => 'KR-555',
            'merek'                 => 'Bendix',
            'tahun'                 => null,
            'id_kategori_sparepart' => null,
            'satuan'                => 'set',
            'stok'                  => 0,
        ]);
        $sp2 = DB::table('sparepart')->where('kode', 'SP-002')->first();
        $this->assertSame(0.0, (float) $sp2->harga_standar);
        $this->assertDatabaseMissing('sparepart_mutasi', ['id_sparepart' => $sp2->id_sparepart]);
    }

    public function test_import_campuran_hitungan_dan_alasan_akurat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeSparepart('SP-EXIST');

        $file = $this->makeXlsxUploadedFile([
            ['SP-OK', 'Valid Satu', 'SN-1', '', '', '', '', '', ''],
            ['', 'Tanpa Kode', 'SN-2', '', '', '', '', '', ''],
            ['SP-NOSER', 'Tanpa Serial', '', '', '', '', '', '', ''],
            ['SP-EXIST', 'Duplikat DB', 'SN-3', '', '', '', '', '', ''],
            ['SP-KAT', 'Kategori Salah', 'SN-4', '', '', 'Tidak Ada', '', '', ''],
            ['SP-SAT', 'Satuan Salah', 'SN-5', '', '', '', 'botol', '', ''],
            ['SP-THN', 'Tahun Salah', 'SN-6', '', 1800, '', '', '', ''],
            ['SP-STK', 'Stok Salah', 'SN-7', '', '', '', '', '', -5],
            ['SP-NAMA', '', 'SN-8', '', '', '', '', '', ''],
            ['SP-HRG', 'Harga Salah', 'SN-9', '', '', '', '', 'abc', ''],
        ]);

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);

        $gagal = $res->json('data.gagal');
        $this->assertCount(9, $gagal);

        $byBaris = collect($gagal)->keyBy('baris');

        $this->assertSame('Kode wajib diisi', $byBaris[3]['alasan']);
        $this->assertSame('Tanpa Kode', $byBaris[3]['nama']);
        $this->assertSame('Serial number wajib diisi', $byBaris[4]['alasan']);
        $this->assertSame('Kode spare part sudah digunakan', $byBaris[5]['alasan']);
        $this->assertSame('Kategori tidak ditemukan', $byBaris[6]['alasan']);
        $this->assertSame('Satuan harus pcs, set, atau liter', $byBaris[7]['alasan']);
        $this->assertSame('Tahun tidak valid', $byBaris[8]['alasan']);
        $this->assertSame('Stok awal tidak valid', $byBaris[9]['alasan']);
        $this->assertSame('Nama wajib diisi', $byBaris[10]['alasan']);
        $this->assertSame('', $byBaris[10]['nama']);
        $this->assertSame('Harga standar tidak valid', $byBaris[11]['alasan']);

        $this->assertDatabaseHas('sparepart', ['kode' => 'SP-OK', 'nama' => 'Valid Satu', 'serial_number' => 'SN-1', 'satuan' => 'pcs', 'stok' => 0]);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-NOSER']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-KAT']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-SAT']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-THN']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-STK']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-NAMA']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-HRG']);
        $this->assertSame(1, DB::table('sparepart')->where('kode', 'SP-EXIST')->count());
    }

    public function test_import_teks_melebihi_panjang_kolom_dilaporkan_gagal_tanpa_menghentikan_baris_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['SP-AWAL', 'Sebelum', 'SN-AWAL', '', '', '', '', '', ''],
            [str_repeat('K', 51), 'Kode Panjang', 'SN-K', '', '', '', '', '', ''],
            ['SP-NAMA', str_repeat('N', 151), 'SN-N', '', '', '', '', '', ''],
            ['SP-SER', 'Serial Panjang', str_repeat('S', 101), '', '', '', '', '', ''],
            ['SP-MRK', 'Merek Panjang', 'SN-M', str_repeat('M', 101), '', '', '', '', ''],
            ['SP-BATAS', 'Tepat Batas', str_repeat('S', 100), str_repeat('M', 100), '', '', '', '', ''],
            ['SP-AKHIR', 'Sesudah', 'SN-AKHIR', '', '', '', '', '', ''],
        ]);

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 3);

        $gagal = $res->json('data.gagal');
        $this->assertCount(4, $gagal);

        $byBaris = collect($gagal)->keyBy('baris');
        $this->assertSame('Kode maksimal 50 karakter', $byBaris[3]['alasan']);
        $this->assertSame('Kode Panjang', $byBaris[3]['nama']);
        $this->assertSame('Nama maksimal 150 karakter', $byBaris[4]['alasan']);
        $this->assertSame('Serial number maksimal 100 karakter', $byBaris[5]['alasan']);
        $this->assertSame('Merek maksimal 100 karakter', $byBaris[6]['alasan']);

        $this->assertDatabaseHas('sparepart', ['kode' => 'SP-AWAL']);
        $this->assertDatabaseHas('sparepart', ['kode' => 'SP-BATAS', 'serial_number' => str_repeat('S', 100), 'merek' => str_repeat('M', 100)]);
        $this->assertDatabaseHas('sparepart', ['kode' => 'SP-AKHIR']);
        $this->assertDatabaseMissing('sparepart', ['kode' => str_repeat('K', 51)]);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-NAMA']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-SER']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-MRK']);
    }

    public function test_import_kode_duplikat_di_dalam_file_menandai_kedua_baris(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['SP-DUP', 'Satu', 'SN-A', '', '', '', '', '', ''],
            ['sp-dup', 'Dua', 'SN-B', '', '', '', '', '', ''],
        ]);

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $gagal = $res->json('data.gagal');
        $this->assertCount(2, $gagal);
        $this->assertSame('Kode duplikat di dalam file', $gagal[0]['alasan']);
        $this->assertSame('Kode duplikat di dalam file', $gagal[1]['alasan']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-DUP']);
    }

    public function test_import_baris_kosong_total_dilewati_tanpa_dihitung(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['SP-OKE', 'Oke Saja', 'SN-OKE', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', ''],
        ]);

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1)
            ->assertJsonPath('data.gagal', []);
    }

    public function test_import_file_bukan_excel_mengembalikan_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = UploadedFile::fake()->create('data.txt', 10, 'text/plain');

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);

        $res->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    public function test_import_kategori_milik_perusahaan_lain_tidak_cocok(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKategori('Filter', $this->makePerusahaanLain());

        $file = $this->makeXlsxUploadedFile([
            ['SP-001', 'Filter Oli', 'FO-1', '', '', 'Filter', '', '', ''],
        ]);

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $gagal = $res->json('data.gagal');
        $this->assertCount(1, $gagal);
        $this->assertSame('Kategori tidak ditemukan', $gagal[0]['alasan']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'SP-001']);
    }

    public function test_import_kode_huruf_kecil_tersimpan_huruf_besar(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            [' sp-abc ', 'Busi', 'BS-1', '', '', '', 'Liter', '', ''],
        ]);

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1)
            ->assertJsonPath('data.gagal', []);

        $this->assertDatabaseHas('sparepart', ['kode' => 'SP-ABC', 'nama' => 'Busi', 'satuan' => 'liter']);
        $this->assertDatabaseMissing('sparepart', ['kode' => 'sp-abc']);
    }
}
