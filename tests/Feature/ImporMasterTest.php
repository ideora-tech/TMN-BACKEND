<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImporMasterTest extends TestCase
{
    use RefreshDatabase;

    private const PERUSAHAAN_LAIN = 'b8f3c1a2-0000-4000-8000-000000000099';

    private function berkas(array $baris, array $judul): UploadedFile
    {
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

        return new UploadedFile($path, 'impor.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function impor(string $master, array $baris, array $judul)
    {
        return $this->post("/api/{$master}/import", ['file' => $this->berkas($baris, $judul)], ['Accept' => 'application/json']);
    }

    private function unggahUlang(string $master, string $path)
    {
        $salinan = sys_get_temp_dir() . '/' . Str::random(10) . '.xlsx';
        copy($path, $salinan);

        return $this->post("/api/{$master}/import", [
            'file' => new UploadedFile($salinan, 'data.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['Accept' => 'application/json']);
    }

    private function gagalPerBaris($res): array
    {
        return collect($res->json('data.gagal'))->mapWithKeys(fn ($g) => [$g['baris'] => $g['alasan']])->all();
    }

    private function lokasi(string $nama, ?string $kota = null, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('lokasi')->insert(array_merge([
            'id_lokasi' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_lokasi' => $nama, 'kota' => $kota, 'aktif' => 1, 'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    private function hanyaIzinLihat(string $path, string $kodePeran): void
    {
        $this->ensurePerusahaan();
        $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => trim($path, '/'), 'path' => $path, 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        DB::table('izin_peran')->where('kode_peran', $kodePeran)->where('id_menu', $idMenu)->delete();
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => $kodePeran,
            'id_menu' => $idMenu, 'aksi' => 'lihat', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
    }

    public function test_klien_import_menambah_dan_menolak_nama_ganda(): void
    {
        $this->actingAsRole('SUPERADMIN');
        DB::table('klien')->insert([
            'id_klien' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-LAMA', 'nama_klien' => 'PT Sudah Ada', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('klien')->insert([
            'id_klien' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_LAIN,
            'kode_klien' => 'KLN-TETANGGA', 'nama_klien' => 'PT Milik Tetangga', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $judul = ['nama_klien', 'kontak_pic', 'telepon', 'email', 'alamat', 'aktif'];
        $res = $this->impor('klien', [
            ['nama_klien' => 'PT Maju Bersama', 'kontak_pic' => 'Ibu Rina', 'telepon' => 215551234, 'email' => 'rina@maju.co.id', 'aktif' => 'tidak'],
            ['nama_klien' => 'pt  sudah ada'],
            ['nama_klien' => 'PT Kembar'],
            ['nama_klien' => 'pt kembar'],
            ['kontak_pic' => 'Tanpa Nama'],
            ['nama_klien' => 'PT Email Salah', 'email' => 'bukan-email'],
            ['nama_klien' => 'PT Milik Tetangga'],
        ], $judul);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 2);
        $gagal = $this->gagalPerBaris($res);
        $this->assertSame([3, 4, 5, 6, 7], array_keys($gagal));
        $this->assertSame('Nama klien sudah terdaftar', $gagal[3]);
        $this->assertStringContainsString('duplikat', $gagal[4]);
        $this->assertStringContainsString('nama_klien wajib', $gagal[6]);
        $this->assertStringContainsString('email', $gagal[7]);

        $baru = DB::table('klien')->where('id_perusahaan', self::PERUSAHAAN_ID)->where('nama_klien', 'PT Maju Bersama')->first();
        $this->assertNotNull($baru);
        $this->assertNotEmpty($baru->kode_klien);
        $this->assertSame('215551234', $baru->telepon);
        $this->assertSame(0, (int) $baru->aktif);
        $this->assertTrue(DB::table('klien')->where('id_perusahaan', self::PERUSAHAAN_ID)->where('nama_klien', 'PT Milik Tetangga')->exists());
    }

    public function test_klien_template_dan_export_bisa_diimport_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $judul = ['nama_klien', 'kontak_pic', 'telepon', 'email', 'alamat', 'aktif'];
        $this->impor('klien', [
            ['nama_klien' => 'PT Satu', 'telepon' => '0812000111', 'alamat' => 'Jl. Merdeka No. 1'],
            ['nama_klien' => 'PT Dua', 'aktif' => 'tidak'],
        ], $judul)->assertStatus(200)->assertJsonPath('data.berhasil', 2);

        $template = $this->get('/api/klien/import/template')->assertStatus(200);
        $buku = IOFactory::load($template->baseResponse->getFile()->getPathname());
        $this->assertSame(['Klien', 'Petunjuk'], $buku->getSheetNames());
        $this->assertSame($judul, $buku->getSheetByName('Klien')->rangeToArray('A1:F1')[0]);

        $unduh = $this->get('/api/klien/export/excel')->assertStatus(200);
        $path  = $unduh->baseResponse->getFile()->getPathname();
        $data  = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        $this->assertSame($judul, $data[0]);
        $this->assertCount(3, $data);
        $this->assertSame('PT Dua', $data[1][0]);
        $this->assertSame('tidak', $data[1][5]);
        $this->assertSame('0812000111', $data[2][2]);

        DB::table('klien')->delete();
        $this->unggahUlang('klien', $path)->assertStatus(200)->assertJsonPath('data.berhasil', 2)->assertJsonPath('data.gagal', []);
        $this->assertSame(0, (int) DB::table('klien')->where('nama_klien', 'PT Dua')->value('aktif'));
    }

    public function test_lokasi_import_membedakan_nama_dan_kota(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->lokasi('Gudang Utama', 'Bekasi');

        $judul = ['nama_lokasi', 'kota', 'alamat', 'aktif'];
        $res = $this->impor('lokasi', [
            ['nama_lokasi' => 'Gudang Utama', 'kota' => 'Bandung', 'alamat' => 'Jl. Soekarno Hatta'],
            ['nama_lokasi' => 'gudang utama', 'kota' => 'BEKASI'],
            ['nama_lokasi' => 'Pelabuhan', 'kota' => 'Jakarta'],
            ['nama_lokasi' => 'Pelabuhan', 'kota' => 'Jakarta'],
            ['nama_lokasi' => 'Depo', 'aktif' => 'mungkin'],
            ['kota' => 'Tanpa Nama'],
        ], $judul);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $gagal = $this->gagalPerBaris($res);
        $this->assertSame([3, 4, 5, 6, 7], array_keys($gagal));
        $this->assertStringContainsString('sudah terdaftar', $gagal[3]);
        $this->assertStringContainsString('duplikat', $gagal[4]);
        $this->assertStringContainsString('aktif', $gagal[6]);
        $this->assertStringContainsString('nama_lokasi wajib', $gagal[7]);
        $this->assertSame(2, DB::table('lokasi')->where('nama_lokasi', 'Gudang Utama')->count());

        $unduh = $this->get('/api/lokasi/export/excel')->assertStatus(200);
        $data  = IOFactory::load($unduh->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);
        $this->assertSame($judul, $data[0]);
        $this->assertCount(3, $data);
        $this->assertSame(['Petunjuk'], array_slice(IOFactory::load($this->get('/api/lokasi/import/template')->baseResponse->getFile()->getPathname())->getSheetNames(), 1));
    }

    public function test_supplier_import_dan_izin_lihat_saja_tidak_boleh_import(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $judul = ['nama', 'telepon', 'alamat', 'aktif'];

        $res = $this->impor('supplier', [
            ['nama' => 'Toko Sumber Jaya', 'telepon' => '081234567890'],
            ['nama' => 'Toko Sumber Jaya'],
            ['nama' => 'Bengkel Maju', 'aktif' => 'tidak'],
        ], $judul);
        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $this->assertSame([2, 3], array_keys($this->gagalPerBaris($res)));
        $this->assertSame(0, (int) DB::table('supplier')->where('nama', 'Bengkel Maju')->value('aktif'));

        $ulang = $this->impor('supplier', [['nama' => 'bengkel maju']], $judul)->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $this->assertSame('Nama supplier sudah terdaftar', $this->gagalPerBaris($ulang)[2]);

        $unduh = $this->get('/api/supplier/export/excel')->assertStatus(200);
        $data  = IOFactory::load($unduh->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);
        $this->assertSame([$judul, ['Bengkel Maju', null, null, 'tidak']], $data);

        $this->hanyaIzinLihat('/supplier', 'SALES');
        $this->actingAsRole('SALES');
        $this->get('/api/supplier/import/template')->assertStatus(200);
        $this->get('/api/supplier/export/excel')->assertStatus(200);
        $this->impor('supplier', [['nama' => 'Tidak Boleh']], $judul)->assertStatus(403);
        $this->assertFalse(DB::table('supplier')->where('nama', 'Tidak Boleh')->exists());
    }

    public function test_barang_import_dengan_kategori_harga_dan_kode_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKategori = (string) Str::uuid();
        DB::table('kategori_barang')->insert([
            'id_kategori_barang' => $idKategori, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'ATK', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('kategori_barang')->insert([
            'id_kategori_barang' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_LAIN,
            'nama' => 'Kategori Tetangga', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $judul = ['nama', 'kategori', 'satuan', 'harga_standar', 'stok_minimum', 'aktif'];
        $res = $this->impor('barang', [
            ['nama' => 'Kertas HVS A4', 'kategori' => 'atk', 'satuan' => 'rim', 'harga_standar' => 'Rp 55.000', 'stok_minimum' => 5],
            ['nama' => 'Pulpen'],
            ['nama' => 'Kategori Asing', 'kategori' => 'Kategori Tetangga'],
            ['nama' => 'Harga Salah', 'harga_standar' => 'mahal'],
            ['nama' => 'Stok Pecahan', 'stok_minimum' => 2.5],
            ['nama' => 'pulpen'],
        ], $judul);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $gagal = $this->gagalPerBaris($res);
        $this->assertSame([3, 4, 5, 6, 7], array_keys($gagal));
        $this->assertStringContainsString('duplikat', $gagal[3]);
        $this->assertStringContainsString('tidak ditemukan', $gagal[4]);
        $this->assertStringContainsString('harga_standar', $gagal[5]);
        $this->assertStringContainsString('stok_minimum', $gagal[6]);

        $barang = DB::table('barang')->where('nama', 'Kertas HVS A4')->first();
        $this->assertNotNull($barang);
        $this->assertSame($idKategori, $barang->id_kategori_barang);
        $this->assertSame('rim', $barang->satuan);
        $this->assertEquals(55000, (float) $barang->harga_standar);
        $this->assertSame(5, (int) $barang->stok_minimum);
        $this->assertSame(0, (int) $barang->stok);
        $this->assertNotEmpty($barang->kode);

        $template = IOFactory::load($this->get('/api/barang/import/template')->assertStatus(200)->baseResponse->getFile()->getPathname());
        $this->assertSame(['Barang', 'Petunjuk', 'Referensi'], $template->getSheetNames());
        $referensi = json_encode($template->getSheetByName('Referensi')->toArray());
        $this->assertStringContainsString('ATK', $referensi);
        $this->assertStringNotContainsString('Kategori Tetangga', $referensi);

        $unduh = $this->get('/api/barang/export/excel')->assertStatus(200);
        $data  = IOFactory::load($unduh->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);
        $this->assertSame($judul, $data[0]);
        $this->assertSame(['Kertas HVS A4', 'ATK', 'rim'], array_slice($data[1], 0, 3));
        $this->assertEquals(55000, $data[1][3]);
        $this->assertSame('ya', $data[1][5]);
    }

    public function test_rute_import_mencocokkan_lokasi_dan_meminta_kota_bila_nama_kembar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idCikarang = $this->lokasi('Gudang Cikarang', 'Bekasi');
        $idPriok    = $this->lokasi('Pelabuhan Tanjung Priok', 'Jakarta Utara');
        $this->lokasi('Depo', 'Bandung');
        $idDepoSby  = $this->lokasi('Depo', 'Surabaya');
        $this->lokasi('Lokasi Mati', 'Solo', ['aktif' => 0]);
        $this->lokasi('Lokasi Tetangga', 'Medan', ['id_perusahaan' => self::PERUSAHAAN_LAIN]);

        $judul = ['nama_rute', 'lokasi_asal', 'kota_asal', 'lokasi_tujuan', 'kota_tujuan', 'estimasi_jarak_km', 'estimasi_durasi_menit', 'keterangan', 'aktif'];
        $res = $this->impor('rute', [
            ['nama_rute' => 'Cikarang - Priok', 'lokasi_asal' => 'gudang cikarang', 'lokasi_tujuan' => 'Pelabuhan Tanjung Priok', 'estimasi_jarak_km' => 45.5, 'estimasi_durasi_menit' => 120],
            ['nama_rute' => 'Cikarang - Depo', 'lokasi_asal' => 'Gudang Cikarang', 'lokasi_tujuan' => 'Depo'],
            ['nama_rute' => 'Cikarang - Depo Surabaya', 'lokasi_asal' => 'Gudang Cikarang', 'lokasi_tujuan' => 'Depo', 'kota_tujuan' => 'surabaya'],
            ['nama_rute' => 'Ke Lokasi Mati', 'lokasi_tujuan' => 'Lokasi Mati'],
            ['nama_rute' => 'Ke Tetangga', 'lokasi_tujuan' => 'Lokasi Tetangga'],
            ['nama_rute' => 'Durasi Pecahan', 'estimasi_durasi_menit' => 90.5],
            ['nama_rute' => 'cikarang - priok'],
            ['nama_rute' => 'Tanpa Lokasi', 'keterangan' => 'Belum ditentukan', 'aktif' => 'tidak'],
        ], $judul);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 2);
        $gagal = $this->gagalPerBaris($res);
        $this->assertSame([2, 3, 5, 6, 7, 8], array_keys($gagal));
        $this->assertStringContainsString('duplikat', $gagal[2]);
        $this->assertStringContainsString('isi kolom kota_tujuan', $gagal[3]);
        $this->assertStringContainsString('tidak ditemukan', $gagal[5]);
        $this->assertStringContainsString('tidak ditemukan', $gagal[6]);
        $this->assertStringContainsString('estimasi_durasi_menit', $gagal[7]);

        $rute = DB::table('rute')->where('nama_rute', 'Cikarang - Depo Surabaya')->first();
        $this->assertNotNull($rute);
        $this->assertSame($idCikarang, $rute->id_lokasi_asal);
        $this->assertSame($idDepoSby, $rute->id_lokasi_tujuan);
        $this->assertSame('Gudang Cikarang', $rute->asal);
        $this->assertSame('Depo', $rute->tujuan);
        $this->assertNotEmpty($rute->kode_rute);
        $this->assertSame(0, (int) DB::table('rute')->where('nama_rute', 'Tanpa Lokasi')->value('aktif'));

        $ulang = $this->impor('rute', [
            ['nama_rute' => 'Cikarang - Priok', 'lokasi_asal' => 'Gudang Cikarang', 'lokasi_tujuan' => 'Pelabuhan Tanjung Priok', 'estimasi_jarak_km' => '45,5', 'estimasi_durasi_menit' => 120],
        ], $judul)->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $priok = DB::table('rute')->where('nama_rute', 'Cikarang - Priok')->first();
        $this->assertSame($idPriok, $priok->id_lokasi_tujuan);
        $this->assertEquals(45.5, (float) $priok->estimasi_jarak_km);
        $this->assertSame(120, (int) $priok->estimasi_durasi_menit);

        $template = IOFactory::load($this->get('/api/rute/import/template')->assertStatus(200)->baseResponse->getFile()->getPathname());
        $referensi = json_encode($template->getSheetByName('Referensi')->toArray());
        $this->assertStringContainsString('Gudang Cikarang', $referensi);
        $this->assertStringNotContainsString('Lokasi Mati', $referensi);
        $this->assertStringNotContainsString('Lokasi Tetangga', $referensi);

        $unduh = $this->get('/api/rute/export/excel')->assertStatus(200);
        $path  = $unduh->baseResponse->getFile()->getPathname();
        $data  = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        $this->assertSame($judul, $data[0]);
        $this->assertCount(4, $data);
        $this->assertSame(['Cikarang - Depo Surabaya', 'Gudang Cikarang', 'Bekasi', 'Depo', 'Surabaya'], array_slice($data[1], 0, 5));

        DB::table('rute')->delete();
        $this->unggahUlang('rute', $path)->assertStatus(200)->assertJsonPath('data.berhasil', 3)->assertJsonPath('data.gagal', []);
        $this->assertSame($idDepoSby, DB::table('rute')->where('nama_rute', 'Cikarang - Depo Surabaya')->value('id_lokasi_tujuan'));
    }

    public function test_file_salah_format_ditolak_di_semua_master(): void
    {
        $this->actingAsRole('SUPERADMIN');

        foreach (['klien' => 'nama_klien', 'lokasi' => 'nama_lokasi', 'supplier' => 'nama', 'barang' => 'nama', 'rute' => 'nama_rute'] as $master => $kolomNama) {
            $tanpaKolom = $this->impor($master, [['lain' => 'x']], ['lain'])->assertStatus(422);
            $this->assertStringContainsString('tidak sesuai template', (string) $tanpaKolom->json('message'), $master);

            $kolomAsing = $this->impor($master, [[$kolomNama => 'Data', 'Catatan Saya' => 'x']], [$kolomNama, 'Catatan Saya'])->assertStatus(422);
            $this->assertStringContainsString('Judul kolom tidak dikenal', (string) $kolomAsing->json('message'), $master);

            $kosong = $this->impor($master, [], [$kolomNama])->assertStatus(422);
            $this->assertStringContainsString('tidak berisi data', (string) $kosong->json('message'), $master);

            $this->post("/api/{$master}/import", [], ['Accept' => 'application/json'])->assertStatus(422);
        }

        $this->assertSame(0, DB::table('klien')->count() + DB::table('lokasi')->count() + DB::table('supplier')->count() + DB::table('barang')->count() + DB::table('rute')->count());
    }

    private function bukuManual(callable $isi): UploadedFile
    {
        $buku = new Spreadsheet();
        $isi($buku);

        $path = sys_get_temp_dir() . '/' . Str::random(10) . '.xlsx';
        (new Xlsx($buku))->save($path);

        return new UploadedFile($path, 'impor.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_kode_otomatis_yang_bentrok_tidak_menggagalkan_import(): void
    {
        $this->actingAsRole('SUPERADMIN');
        foreach (['KLN-0001', 'KLN-0002'] as $kode) {
            DB::table('klien')->insert([
                'id_klien' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_LAIN,
                'kode_klien' => $kode, 'nama_klien' => 'Tetangga ' . $kode, 'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('rute')->insert([
            'id_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-0001', 'nama_rute' => 'Rute Lama', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->impor('klien', [['nama_klien' => 'PT Satu'], ['nama_klien' => 'PT Dua'], ['nama_klien' => 'PT Tiga']], ['nama_klien'])
            ->assertStatus(200)->assertJsonPath('data.berhasil', 3)->assertJsonPath('data.gagal', []);
        $kodeKlien = DB::table('klien')->where('id_perusahaan', self::PERUSAHAAN_ID)->pluck('kode_klien')->all();
        $this->assertCount(3, array_unique($kodeKlien));
        $this->assertSame([], array_intersect($kodeKlien, ['KLN-0001', 'KLN-0002']));

        $this->impor('rute', [['nama_rute' => 'Rute Satu'], ['nama_rute' => 'Rute Dua']], ['nama_rute'])
            ->assertStatus(200)->assertJsonPath('data.berhasil', 2)->assertJsonPath('data.gagal', []);
        $this->assertSame(3, DB::table('rute')->where('id_perusahaan', self::PERUSAHAAN_ID)->distinct()->count('kode_rute'));
    }

    public function test_export_menulis_teks_berawalan_sama_dengan_sebagai_teks_biasa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        DB::table('klien')->insert([
            'id_klien' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-X', 'nama_klien' => '=HYPERLINK("http://contoh.id","klik")', 'alamat' => '=1+1',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $unduh = $this->get('/api/klien/export/excel')->assertStatus(200);
        $sheet = IOFactory::load($unduh->baseResponse->getFile()->getPathname())->getActiveSheet();

        foreach (['A2' => '=HYPERLINK("http://contoh.id","klik")', 'E2' => '=1+1'] as $sel => $isi) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($sel)->getDataType(), $sel);
            $this->assertSame($isi, $sheet->getCell($sel)->getValue(), $sel);
        }
    }

    public function test_hanya_sheet_pertama_yang_dibaca_dan_galat_rumus_ditolak_per_baris(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $duaSheet = $this->bukuManual(function (Spreadsheet $buku) {
            $data = $buku->getActiveSheet()->setTitle('Data');
            $data->fromArray([['nama', 'telepon'], ['Toko Satu', '0811'], ['#REF!', '0812'], ['Toko Tiga', '#N/A']], null, 'A1', true);

            $lain = $buku->createSheet()->setTitle('Lain');
            $lain->fromArray([['kolom_asing', 'x'], ['isi', 'y']], null, 'A1', true);
        });
        $res = $this->post('/api/supplier/import', ['file' => $duaSheet], ['Accept' => 'application/json'])
            ->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $gagal = $this->gagalPerBaris($res);
        $this->assertSame([3, 4], array_keys($gagal));
        $this->assertStringContainsString('galat rumus', $gagal[3]);
        $this->assertStringContainsString('telepon', $gagal[4]);
        $this->assertSame(['Toko Satu'], DB::table('supplier')->pluck('nama')->all());

        $sheetAngka = $this->bukuManual(function (Spreadsheet $buku) {
            $buku->getActiveSheet()->setTitle('2026')->fromArray([['nama'], ['Toko Angka']], null, 'A1', true);
        });
        $ditolak = $this->post('/api/supplier/import', ['file' => $sheetAngka], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertStringContainsString('Nama sheet pertama', (string) $ditolak->json('message'));

        $terlaluLebar = $this->bukuManual(function (Spreadsheet $buku) {
            $sheet = $buku->getActiveSheet()->setTitle('Data');
            $sheet->fromArray([['nama'], ['Toko Lebar']], null, 'A1', true);
            $sheet->setCellValue('DA1', 'jauh');
        });
        $lebar = $this->post('/api/supplier/import', ['file' => $terlaluLebar], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertStringContainsString('kolom', (string) $lebar->json('message'));

        $this->assertSame(1, DB::table('supplier')->count());
    }

    public function test_rute_lokasi_tanpa_kota_dipilih_bila_kolom_kota_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idDepoPolos = $this->lokasi('Depo');
        $idDepoBdg   = $this->lokasi('Depo', 'Bandung');

        $judul = ['nama_rute', 'lokasi_asal', 'kota_asal', 'lokasi_tujuan', 'kota_tujuan'];
        $this->impor('rute', [
            ['nama_rute' => 'Ke Depo Polos', 'lokasi_tujuan' => 'Depo'],
            ['nama_rute' => 'Ke Depo Bandung', 'lokasi_tujuan' => 'Depo', 'kota_tujuan' => 'Bandung'],
        ], $judul)->assertStatus(200)->assertJsonPath('data.berhasil', 2)->assertJsonPath('data.gagal', []);

        $this->assertSame($idDepoPolos, DB::table('rute')->where('nama_rute', 'Ke Depo Polos')->value('id_lokasi_tujuan'));
        $this->assertSame($idDepoBdg, DB::table('rute')->where('nama_rute', 'Ke Depo Bandung')->value('id_lokasi_tujuan'));

        $unduh = $this->get('/api/rute/export/excel')->assertStatus(200);
        DB::table('rute')->delete();
        $this->unggahUlang('rute', $unduh->baseResponse->getFile()->getPathname())
            ->assertStatus(200)->assertJsonPath('data.berhasil', 2)->assertJsonPath('data.gagal', []);
    }
}
