<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Karyawan\KolomExcelKaryawan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class KaryawanImportTest extends TestCase
{
    use RefreshDatabase;

    private const PERUSAHAAN_LAIN = 'b8f3c1a2-0000-4000-8000-000000000099';

    private function berkas(array $baris, ?array $judul = null): UploadedFile
    {
        $judul ??= KolomExcelKaryawan::judul();

        $isi = [];
        foreach ($baris as $satu) {
            $isi[] = array_map(static fn ($kolom) => $satu[$kolom] ?? null, $judul);
        }

        $export = new class($isi, $judul) implements FromArray, WithHeadings, WithStrictNullComparison {
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

        return new UploadedFile($path, 'karyawan.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function jabatan(string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert(array_merge([
            'id_jabatan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => $kode, 'nama_jabatan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    private function lokasi(string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lokasi_kantor')->insert(array_merge([
            'id_lokasi' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_lokasi' => $kode, 'nama_lokasi' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    private function karyawanLama(string $nik, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert(array_merge([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => 'Karyawan Lama', 'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    private function import(array $baris, ?array $judul = null)
    {
        return $this->post('/api/karyawan/import', ['file' => $this->berkas($baris, $judul)], ['Accept' => 'application/json']);
    }

    public function test_import_baris_lengkap_membuat_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->jabatan('Staff Operasional', 'JBT-0001');
        $idLokasi  = $this->lokasi('Kantor Pusat', 'LOK-0001');

        $res = $this->import([[
            'nik' => 'KRY-0001', 'nama_karyawan' => 'Budi Santoso', 'nik_ktp' => '3201234567890001',
            'jabatan' => 'staff operasional', 'lokasi_kantor' => 'LOK-0001',
            'tanggal_masuk' => '15/01/2026', 'status_kepegawaian' => 'Kontrak', 'gaji_pokok' => '5.000.000',
            'jenis_kelamin' => 'Laki-laki', 'tanggal_lahir' => '1990-05-20', 'email' => 'budi@contoh.co.id',
            'status_pernikahan' => 'Belum Menikah', 'jumlah_tanggungan' => 2, 'status_ptkp' => 'k/2',
            'ikut_bpjs_kesehatan' => 'Ya', 'no_bpjs_kesehatan' => '0001234567890',
        ]]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1)->assertJsonPath('data.gagal', []);

        $k = DB::table('karyawan')->where('nik', 'KRY-0001')->first();
        $this->assertNotNull($k);
        $this->assertSame(self::PERUSAHAAN_ID, $k->id_perusahaan);
        $this->assertSame('Budi Santoso', $k->nama_karyawan);
        $this->assertSame('3201234567890001', $k->nik_ktp);
        $this->assertSame($idJabatan, $k->id_jabatan);
        $this->assertSame($idLokasi, $k->id_lokasi);
        $this->assertSame('2026-01-15', substr((string) $k->tanggal_masuk, 0, 10));
        $this->assertSame('1990-05-20', substr((string) $k->tanggal_lahir, 0, 10));
        $this->assertSame('kontrak', $k->status_kepegawaian);
        $this->assertEquals(5000000, (float) $k->gaji_pokok);
        $this->assertSame('L', $k->jenis_kelamin);
        $this->assertSame('belum_menikah', $k->status_pernikahan);
        $this->assertSame(2, (int) $k->jumlah_tanggungan);
        $this->assertSame('K/2', $k->status_ptkp);
        $this->assertSame(1, (int) $k->ikut_bpjs_kesehatan);
        $this->assertSame(0, (int) $k->ikut_bpjs_ketenagakerjaan);
        $this->assertSame(1, (int) $k->aktif);
    }

    public function test_import_hanya_kolom_wajib_memakai_nilai_bawaan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->import([['nik' => 'KRY-0002', 'nama_karyawan' => 'Siti Aminah']])
            ->assertStatus(200)->assertJsonPath('data.berhasil', 1);

        $k = DB::table('karyawan')->where('nik', 'KRY-0002')->first();
        $this->assertSame('tetap', $k->status_kepegawaian);
        $this->assertEquals(0, (float) $k->gaji_pokok);
        $this->assertSame(0, (int) $k->jumlah_tanggungan);
        $this->assertSame(1, (int) $k->aktif);
        $this->assertNull($k->id_jabatan);
        $this->assertNull($k->tanggal_masuk);
    }

    public function test_nik_berupa_angka_disimpan_utuh_dan_tanggal_serial_excel_terbaca(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->import([['nik' => 2026001, 'nama_karyawan' => 'Andi', 'telepon' => 81234567890, 'tanggal_masuk' => 46037]])
            ->assertStatus(200)->assertJsonPath('data.berhasil', 1);

        $k = DB::table('karyawan')->where('nik', '2026001')->first();
        $this->assertNotNull($k);
        $this->assertSame('81234567890', $k->telepon);
        $this->assertSame('2026-01-15', substr((string) $k->tanggal_masuk, 0, 10));
    }

    public function test_baris_gagal_dilaporkan_dan_baris_lain_tetap_masuk(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->jabatan('Staff', 'JBT-0001');
        $this->jabatan('Staff', 'JBT-0002');
        $this->karyawanLama('KRY-LAMA');
        $this->karyawanLama('KRY-HAPUS', ['dihapus_pada' => now()]);

        $res = $this->import([
            ['nik' => 'KRY-0001', 'nama_karyawan' => 'Baris Baik'],
            ['nama_karyawan' => 'Tanpa NIK'],
            ['nik' => 'KRY-0003'],
            ['nik' => 'KRY-DOBEL', 'nama_karyawan' => 'Dobel Satu'],
            ['nik' => 'kry-dobel', 'nama_karyawan' => 'Dobel Dua'],
            ['nik' => 'KRY-LAMA', 'nama_karyawan' => 'Sudah Ada'],
            ['nik' => 'KRY-HAPUS', 'nama_karyawan' => 'Pernah Dihapus'],
            ['nik' => 'KRY-0008', 'nama_karyawan' => 'Jabatan Asing', 'jabatan' => 'Direktur Antariksa'],
            ['nik' => 'KRY-0009', 'nama_karyawan' => 'Jabatan Ganda', 'jabatan' => 'Staff'],
            ['nik' => 'KRY-0010', 'nama_karyawan' => 'Tanggal Salah', 'tanggal_masuk' => '31/02/2026'],
            ['nik' => 'KRY-0011', 'nama_karyawan' => 'Email Salah', 'email' => 'bukan-email'],
            ['nik' => 'KRY-0012', 'nama_karyawan' => 'Status Salah', 'status_kepegawaian' => 'honorer'],
            ['nik' => 'KRY-0013', 'nama_karyawan' => 'Jabatan Pakai Kode', 'jabatan' => 'jbt-0002'],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 2);

        $gagal = collect($res->json('data.gagal'))->keyBy('baris');
        $this->assertCount(11, $gagal);
        $this->assertStringContainsString('nik wajib', $gagal[3]['alasan']);
        $this->assertSame('Tanpa NIK', $gagal[3]['kunci']);
        $this->assertStringContainsString('nama_karyawan wajib', $gagal[4]['alasan']);
        $this->assertStringContainsString('duplikat', $gagal[5]['alasan']);
        $this->assertStringContainsString('duplikat', $gagal[6]['alasan']);
        $this->assertSame('NIK sudah terdaftar', $gagal[7]['alasan']);
        $this->assertStringContainsString('sudah dihapus', $gagal[8]['alasan']);
        $this->assertStringContainsString('tidak ditemukan', $gagal[9]['alasan']);
        $this->assertStringContainsString('isi dengan kodenya', $gagal[10]['alasan']);
        $this->assertStringContainsString('tanggal_masuk', $gagal[11]['alasan']);
        $this->assertStringContainsString('email', $gagal[12]['alasan']);
        $this->assertStringContainsString('status_kepegawaian', $gagal[13]['alasan']);

        $this->assertTrue(DB::table('karyawan')->where('nik', 'KRY-0001')->exists());
        $this->assertTrue(DB::table('karyawan')->where('nik', 'KRY-0013')->whereNotNull('id_jabatan')->exists());
        $this->assertFalse(DB::table('karyawan')->whereIn('nik', ['KRY-DOBEL', 'kry-dobel', 'KRY-0008', 'KRY-0009'])->exists());
        $this->assertSame('Karyawan Lama', DB::table('karyawan')->where('nik', 'KRY-LAMA')->value('nama_karyawan'));
    }

    public function test_jabatan_supir_ikut_membuat_data_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->jabatan('Supir', 'JBT-SPR', ['is_supir' => 1]);

        $this->import([['nik' => 'KRY-SPR', 'nama_karyawan' => 'Joko', 'jabatan' => 'Supir', 'telepon' => '0812000111']])
            ->assertStatus(200)->assertJsonPath('data.berhasil', 1);

        $idKaryawan = DB::table('karyawan')->where('nik', 'KRY-SPR')->value('id_karyawan');
        $supir = DB::table('supir')->where('id_karyawan', $idKaryawan)->first();
        $this->assertNotNull($supir);
        $this->assertSame('Joko', $supir->nama);
    }

    public function test_jabatan_dan_lokasi_perusahaan_lain_atau_nonaktif_tidak_dikenali(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->jabatan('Jabatan Tetangga', 'JBT-X', ['id_perusahaan' => self::PERUSAHAAN_LAIN]);
        $this->jabatan('Jabatan Mati', 'JBT-M', ['aktif' => 0]);
        $this->lokasi('Lokasi Tetangga', 'LOK-X', ['id_perusahaan' => self::PERUSAHAAN_LAIN]);

        $res = $this->import([
            ['nik' => 'A-1', 'nama_karyawan' => 'Satu', 'jabatan' => 'Jabatan Tetangga'],
            ['nik' => 'A-2', 'nama_karyawan' => 'Dua', 'jabatan' => 'JBT-M'],
            ['nik' => 'A-3', 'nama_karyawan' => 'Tiga', 'lokasi_kantor' => 'Lokasi Tetangga'],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $this->assertCount(3, $res->json('data.gagal'));
        $this->assertSame(0, DB::table('karyawan')->count());
    }

    public function test_file_tanpa_data_atau_format_salah_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $kosong = $this->import([])->assertStatus(422);
        $this->assertStringContainsString('tidak berisi data', (string) $kosong->json('message'));

        $salahTemplate = $this->import([['nama' => 'Budi', 'no_sim' => '123']], ['nama', 'no_sim'])->assertStatus(422);
        $this->assertStringContainsString('tidak sesuai template', (string) $salahTemplate->json('message'));

        $kolomAsing = $this->import(
            [['nik' => 'KRY-0001', 'nama_karyawan' => 'Budi', 'Gaji Pokok (Rp)' => 5000000]],
            ['nik', 'nama_karyawan', 'Gaji Pokok (Rp)'],
        )->assertStatus(422);
        $this->assertStringContainsString('Judul kolom tidak dikenal', (string) $kolomAsing->json('message'));

        $this->post('/api/karyawan/import', [], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, DB::table('karyawan')->count());
    }

    public function test_file_rusak_ditolak_422_bukan_500(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $utuh  = file_get_contents($this->berkas([['nik' => 'KRY-0001', 'nama_karyawan' => 'Budi']])->getRealPath());
        $rusak = sys_get_temp_dir() . '/' . Str::random(10) . '.xlsx';
        file_put_contents($rusak, substr($utuh, 0, (int) (strlen($utuh) * 0.6)));

        $this->post('/api/karyawan/import', [
            'file' => new UploadedFile($rusak, 'karyawan.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, DB::table('karyawan')->count());
    }

    public function test_file_melebihi_seribu_baris_ditolak_sebelum_diproses(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $baris = [];
        for ($i = 1; $i <= 1001; $i++) {
            $baris[] = ['nik' => 'KRY-' . $i, 'nama_karyawan' => 'Karyawan ' . $i];
        }

        $res = $this->import($baris, ['nik', 'nama_karyawan'])->assertStatus(422);
        $this->assertStringContainsString('maksimal 1.000', (string) $res->json('message'));
        $this->assertSame(0, DB::table('karyawan')->count());
    }

    public function test_rumus_dihitung_dulu_sebelum_disimpan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->import([[
            'nik' => '="KRY-"&"0007"', 'nama_karyawan' => '=CONCATENATE("Budi"," ","Santoso")',
            'gaji_pokok' => '=5000000+500000', 'tanggal_masuk' => '=DATE(2026,1,15)',
        ]])->assertStatus(200)->assertJsonPath('data.berhasil', 1);

        $k = DB::table('karyawan')->where('nik', 'KRY-0007')->first();
        $this->assertNotNull($k);
        $this->assertSame('Budi Santoso', $k->nama_karyawan);
        $this->assertEquals(5500000, (float) $k->gaji_pokok);
        $this->assertSame('2026-01-15', substr((string) $k->tanggal_masuk, 0, 10));
    }

    public function test_isi_aneh_menjadi_baris_gagal_tanpa_menghentikan_baris_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->import([
            ['nik' => 'B-1', 'nama_karyawan' => 'Baik Satu', 'gaji_pokok' => 5000000, 'tanggal_masuk' => '5/01/2026'],
            ['nik' => 'B-2', 'nama_karyawan' => 'Gaji Tak Hingga', 'gaji_pokok' => '1e999'],
            ['nik' => 'B-3', 'nama_karyawan' => 'Gaji Negatif', 'gaji_pokok' => -100],
            ['nik' => 'B-4', 'nama_karyawan' => 'Tahun Saja', 'tanggal_masuk' => 2026],
            ['nik' => 'B-5', 'nama_karyawan' => 'Tanggal Rapat', 'tanggal_lahir' => '010190'],
            ['nik' => 'B-6', 'nama_karyawan' => 'Tanggungan Pecahan', 'jumlah_tanggungan' => 1.5],
            ['nik' => 'B-7', 'nama_karyawan' => 'Status Lama', 'status_kepegawaian' => 'paruh_waktu'],
            ['nik' => "B-8\u{00A0}", 'nama_karyawan' => 'Spasi Tak Terlihat', 'gaji_pokok' => 'Rp 4.500.000,50'],
            ['nik' => 0, 'nama_karyawan' => 'NIK Nol'],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 3);

        $gagal = collect($res->json('data.gagal'))->keyBy('baris');
        $this->assertSame([3, 4, 5, 6, 7, 8], $gagal->keys()->all());
        $this->assertStringContainsString('gaji_pokok', $gagal[3]['alasan']);
        $this->assertStringContainsString('gaji_pokok', $gagal[4]['alasan']);
        $this->assertStringContainsString('tanggal_masuk', $gagal[5]['alasan']);
        $this->assertStringContainsString('tanggal_lahir', $gagal[6]['alasan']);
        $this->assertStringContainsString('jumlah_tanggungan', $gagal[7]['alasan']);
        $this->assertStringContainsString('status_kepegawaian', $gagal[8]['alasan']);

        $this->assertSame('2026-01-05', substr((string) DB::table('karyawan')->where('nik', 'B-1')->value('tanggal_masuk'), 0, 10));
        $this->assertEquals(4500000.5, (float) DB::table('karyawan')->where('nik', 'B-8')->value('gaji_pokok'));
        $this->assertTrue(DB::table('karyawan')->where('nik', '0')->exists());
    }

    public function test_peran_tanpa_izin_karyawan_ditolak(): void
    {
        $this->actingAsRole('SUPIR');

        $this->import([['nik' => 'KRY-0001', 'nama_karyawan' => 'Budi']])->assertStatus(403);
        $this->get('/api/karyawan/import/template', ['Accept' => 'application/json'])->assertStatus(403);
        $this->get('/api/karyawan/export/excel', ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_peran_lihat_saja_boleh_unduh_tetapi_tidak_boleh_import(): void
    {
        $this->ensurePerusahaan();
        $idMenu = DB::table('menu')->where('path', '/karyawan')->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => 'Karyawan', 'path' => '/karyawan', 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        DB::table('izin_peran')->where('kode_peran', 'SALES')->where('id_menu', $idMenu)->delete();
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'SALES',
            'id_menu' => $idMenu, 'aksi' => 'lihat', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
        $this->actingAsRole('SALES');

        $this->get('/api/karyawan/import/template')->assertStatus(200);
        $this->get('/api/karyawan/export/excel')->assertStatus(200);
        $this->import([['nik' => 'KRY-0001', 'nama_karyawan' => 'Budi']])->assertStatus(403);
        $this->assertSame(0, DB::table('karyawan')->count());
    }

    public function test_template_berisi_tiga_sheet_dan_referensi_master(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->jabatan('Staff Operasional', 'JBT-0001');
        $this->jabatan('Jabatan Mati', 'JBT-M', ['aktif' => 0]);
        $this->lokasi('Kantor Pusat', 'LOK-0001');

        $unduh = $this->get('/api/karyawan/import/template')->assertStatus(200);
        $buku  = IOFactory::load($unduh->baseResponse->getFile()->getPathname());

        $this->assertSame(['Karyawan', 'Petunjuk', 'Referensi'], $buku->getSheetNames());

        $judul = $buku->getSheetByName('Karyawan')->rangeToArray('A1:AE1')[0];
        $this->assertSame(KolomExcelKaryawan::judul(), $judul);
        $this->assertNull($buku->getSheetByName('Karyawan')->getCell('A2')->getValue());

        $lembar = $buku->getSheetByName('Karyawan');
        $formatKolom = static fn (string $huruf) => $buku
            ->getCellXfByIndex((int) $lembar->getColumnDimension($huruf)->getXfIndex())
            ->getNumberFormat()->getFormatCode();
        $this->assertSame('@', $formatKolom('A'));
        $this->assertSame('@', $formatKolom('C'));
        $this->assertNotSame('@', $formatKolom('H'));

        $referensi = $buku->getSheetByName('Referensi')->toArray();
        $this->assertSame(['JBT-0001', 'Staff Operasional'], array_slice($referensi[1], 0, 2));
        $this->assertSame(['LOK-0001', 'Kantor Pusat'], array_slice($referensi[1], 4, 2));
        $this->assertStringNotContainsString('Jabatan Mati', json_encode($referensi));
    }

    public function test_export_memakai_format_import_dan_bisa_diimport_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->jabatan('Staff Operasional', 'JBT-0001');
        $this->karyawanLama('KRY-0001', [
            'nama_karyawan' => 'Budi Santoso', 'id_jabatan' => $idJabatan, 'nik_ktp' => '3201234567890001',
            'tanggal_masuk' => '2026-01-15', 'status_kepegawaian' => 'kontrak', 'gaji_pokok' => 5000000,
            'ikut_bpjs_kesehatan' => 1, 'aktif' => 0,
        ]);
        $this->karyawanLama('KRY-0002', ['nama_karyawan' => 'Citra', 'status_kepegawaian' => 'tetap']);
        $this->karyawanLama('KRY-HAPUS', ['dihapus_pada' => now()]);
        $this->karyawanLama('KRY-LAIN', ['id_perusahaan' => self::PERUSAHAAN_LAIN]);

        $unduh = $this->get('/api/karyawan/export/excel')->assertStatus(200);
        $path  = $unduh->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();
        $data  = $sheet->toArray(null, false, false, false);

        $this->assertSame(KolomExcelKaryawan::judul(), $data[0]);
        $this->assertCount(3, $data);
        $this->assertSame(['KRY-0001', 'Budi Santoso', '3201234567890001', 'Staff Operasional'], array_slice($data[1], 0, 4));

        $peta = array_combine($data[0], $data[1]);
        $this->assertSame('2026-01-15', $peta['tanggal_masuk']);
        $this->assertSame('kontrak', $peta['status_kepegawaian']);
        $this->assertEquals(5000000, $peta['gaji_pokok']);
        $this->assertSame('ya', $peta['ikut_bpjs_kesehatan']);
        $this->assertSame('tidak', $peta['aktif']);
        $this->assertSame('KRY-0002', $data[2][0]);

        $terfilter = $this->get('/api/karyawan/export/excel?status=tetap')->assertStatus(200);
        $this->assertCount(2, IOFactory::load($terfilter->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray());

        DB::table('karyawan')->delete();
        $salinan = sys_get_temp_dir() . '/' . Str::random(10) . '.xlsx';
        copy($path, $salinan);
        $ulang = $this->post('/api/karyawan/import', [
            'file' => new UploadedFile($salinan, 'data-karyawan.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['Accept' => 'application/json']);

        $ulang->assertStatus(200)->assertJsonPath('data.berhasil', 2)->assertJsonPath('data.gagal', []);
        $kembali = DB::table('karyawan')->where('nik', 'KRY-0001')->first();
        $this->assertSame($idJabatan, $kembali->id_jabatan);
        $this->assertSame(0, (int) $kembali->aktif);
        $this->assertSame('3201234567890001', $kembali->nik_ktp);
    }
}
