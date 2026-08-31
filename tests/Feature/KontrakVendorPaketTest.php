<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\SupirVendor\SupirVendorModel;
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

class KontrakVendorPaketTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS_UNIT = [
        'kode_vendor', 'nopol', 'merk', 'jenis', 'jenis_kendaraan',
        'kapasitas', 'tahun', 'masa_berlaku_stnk', 'masa_berlaku_kir',
    ];

    private const HEADINGS_SUPIR = [
        'kode_vendor', 'nama', 'telepon', 'no_sim', 'masa_berlaku_sim',
    ];

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Paket Test',
        ]);
    }

    private function makeKontrak(string $idVendor, string $mekanisme, array $override = []): KontrakVendorModel
    {
        return KontrakVendorModel::create(array_merge([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => $mekanisme,
            'status'        => 'aktif',
        ], $override));
    }

    private function makeArmadaVendor(string $idVendor, array $override = []): ArmadaVendorModel
    {
        return ArmadaVendorModel::create(array_merge([
            'id_vendor' => $idVendor,
            'nopol'     => 'B ' . random_int(1000, 9999) . ' KP',
        ], $override));
    }

    private function makeSupirVendor(string $idVendor, array $override = []): SupirVendorModel
    {
        return SupirVendorModel::create(array_merge([
            'id_vendor' => $idVendor,
            'nama'      => 'Supir Paket ' . Str::random(4),
        ], $override));
    }

    private function makeProyekDenganRute(): array
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Kontrak Paket',
        ]);
        $idProyek = (string) $proyek->id_proyek;

        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $idRute,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RT-' . Str::random(6),
            'nama_rute'     => 'Rute Kontrak Paket',
            'dibuat_pada'   => now(),
        ]);
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(),
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_proyek'      => $idProyek,
            'id_rute'        => $idRute,
            'uang_jalan'     => null,
            'dibuat_pada'    => now(),
        ]);

        return ['id_proyek' => $idProyek, 'id_rute' => $idRute];
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

    private function makeXlsxUploadedFile(array $rows, array $headings): UploadedFile
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

    private function makeTxtUploadedFile(): UploadedFile
    {
        $path = sys_get_temp_dir() . '/' . Str::random(10) . '.txt';
        file_put_contents($path, 'bukan excel');

        return new UploadedFile($path, 'import.txt', 'text/plain', null, true);
    }

    private const HEADINGS_PASANGAN = [
        'nopol', 'merk', 'jenis', 'jenis_kendaraan', 'kapasitas', 'tahun',
        'masa_berlaku_stnk', 'masa_berlaku_kir',
        'nama_driver', 'telepon_driver', 'no_sim_driver',
    ];

    public function test_parse_pasangan_mengembalikan_unit_dan_driver_per_baris(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['B 1111 PU', 'Hino', 'Dump Truck', '', '20 ton', 2021, '2027-06-01', '2027-06-02', 'Budi Pasangan', '0812', 'SIM-PU-1'],
            ['B 2222 PU', '', '', '', '', '', '2027-06-01', '2027-06-02', '', '', ''],
        ], self::HEADINGS_PASANGAN);

        $res = $this->postJson('/api/kontrak-vendor/parse-pasangan', ['file' => $file]);

        $res->assertStatus(200);
        $this->assertSame([], $res->json('data.baris_gagal'));

        $valid = $res->json('data.baris_valid');
        $this->assertCount(2, $valid);
        $this->assertSame('Budi Pasangan', $valid[0]['driver_nama']);
        $this->assertSame('SIM-PU-1', $valid[0]['driver_no_sim']);
        $this->assertNull($valid[1]['driver_nama']);
    }

    public function test_parse_pasangan_driver_tanpa_nama_masuk_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['B 3333 PU', '', '', '', '', '', '2027-06-01', '2027-06-02', '', '0813', ''],
        ], self::HEADINGS_PASANGAN);

        $res = $this->postJson('/api/kontrak-vendor/parse-pasangan', ['file' => $file]);
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data.baris_gagal'));
        $this->assertCount(0, $res->json('data.baris_valid'));
    }

    public function test_template_pasangan_bisa_diunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->get('/api/kontrak-vendor/template-pasangan')->assertStatus(200);
    }

    public function test_create_kontrak_memasangkan_unit_dengan_supir_via_index(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_driver',
            'unit'  => [
                ['nopol' => 'B 1212 PS', 'supir_index' => 1, 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02'],
                ['nopol' => 'B 3434 PS', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02'],
            ],
            'supir' => [
                ['nama' => 'Driver Pertama'],
                ['nama' => 'Driver Kedua'],
            ],
        ]);

        $res->assertStatus(201);

        $idPasangan = \Illuminate\Support\Facades\DB::table('supir_vendor')->where('nama', 'Driver Kedua')->value('id_supir_vendor');
        $this->assertDatabaseHas('armada_vendor', [
            'nopol'                   => 'B 1212 PS',
            'id_supir_vendor_default' => $idPasangan,
        ]);
        $this->assertDatabaseHas('armada_vendor', [
            'nopol'                   => 'B 3434 PS',
            'id_supir_vendor_default' => null,
        ]);
    }

    public function test_create_kontrak_supir_index_tidak_valid_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_driver',
            'unit'  => [['nopol' => 'B 5656 PS', 'supir_index' => 5, 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02']],
            'supir' => [['nama' => 'Driver Satu-satunya']],
        ])->assertStatus(422);
    }

    public function test_timpa_pasangan_sinkron_unit_dan_driver(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');

        $idDriverLama = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idDriverLama, 'id_vendor' => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor, 'nama' => 'Driver Lama', 'dibuat_pada' => now(),
        ]);
        $tetap = $this->makeArmadaVendor($vendor->id_vendor, [
            'nopol' => 'B 100 TPP', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_supir_vendor_default' => $idDriverLama,
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['B 100 TPP', 'Hino', '', '', '', '', '2027-06-01', '2027-06-02', 'Driver Ganti Nama', '0855', 'SIM-TPP-1'],
            ['B 200 TPP', '', '', '', '', '', '2027-06-01', '2027-06-02', 'Driver Baru', '0856', 'SIM-TPP-2'],
        ], self::HEADINGS_PASANGAN);

        $res = $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/timpa-pasangan", ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.ditambah', 1)
            ->assertJsonPath('data.diperbarui', 1)
            ->assertJsonPath('data.driver_ditambah', 1)
            ->assertJsonPath('data.driver_diperbarui', 1)
            ->assertJsonPath('data.gagal', []);

        $this->assertDatabaseHas('supir_vendor', ['id_supir_vendor' => $idDriverLama, 'nama' => 'Driver Ganti Nama']);
        $idDriverBaru = \Illuminate\Support\Facades\DB::table('supir_vendor')->where('nama', 'Driver Baru')->value('id_supir_vendor');
        $this->assertDatabaseHas('armada_vendor', ['nopol' => 'B 200 TPP', 'id_supir_vendor_default' => $idDriverBaru]);
        $this->assertSame($idDriverLama, (string) $tetap->fresh()->id_supir_vendor_default);
    }

    public function test_timpa_pasangan_driver_kosong_melepas_pasangan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');

        $idDriver = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idDriver, 'id_vendor' => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor, 'nama' => 'Driver Dilepas', 'dibuat_pada' => now(),
        ]);
        $unit = $this->makeArmadaVendor($vendor->id_vendor, [
            'nopol' => 'B 300 TPP', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_supir_vendor_default' => $idDriver,
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['B 300 TPP', '', '', '', '', '', '2027-06-01', '2027-06-02', '', '', ''],
        ], self::HEADINGS_PASANGAN);

        $res = $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/timpa-pasangan", ['file' => $file]);

        $res->assertStatus(200)->assertJsonPath('data.driver_dilepas', 1);
        $this->assertNull($unit->fresh()->id_supir_vendor_default);
        $this->assertDatabaseHas('supir_vendor', ['id_supir_vendor' => $idDriver, 'dihapus_pada' => null]);
    }

    public function test_timpa_pasangan_kontrak_unit_only_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');

        $file = $this->makeXlsxUploadedFile([
            ['B 400 TPP', '', '', '', '', '', '2027-06-01', '2027-06-02', '', '', ''],
        ], self::HEADINGS_PASANGAN);

        $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/timpa-pasangan", ['file' => $file])
            ->assertStatus(422);
    }

    public function test_timpa_unit_sinkron_update_tambah_hapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');

        $tetap  = $this->makeArmadaVendor($vendor->id_vendor, ['nopol' => 'B 1000 TP', 'merk' => 'Lama', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);
        $hilang = $this->makeArmadaVendor($vendor->id_vendor, ['nopol' => 'B 2000 TP', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);

        $file = $this->makeXlsxUploadedFile([
            ['', 'B 1000 TP', 'Hino Baru', 'Dump Truck', '', '', 2022, '2027-06-01', '2027-06-02'],
            ['', 'B 3000 TP', 'Isuzu', '', '', '', '', '2027-06-01', '2027-06-02'],
        ], self::HEADINGS_UNIT);

        $res = $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/timpa-unit", ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.ditambah', 1)
            ->assertJsonPath('data.diperbarui', 1)
            ->assertJsonPath('data.dihapus', 1)
            ->assertJsonPath('data.gagal', []);

        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor' => $tetap->id_armada_vendor,
            'merk'             => 'Hino Baru',
            'dihapus_pada'     => null,
        ]);
        $this->assertNotNull($hilang->fresh()->dihapus_pada);
        $this->assertDatabaseHas('armada_vendor', [
            'nopol'             => 'B 3000 TP',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'dihapus_pada'      => null,
        ]);
    }

    public function test_hapus_armada_vendor_yang_dipakai_penugasan_aktif_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $unit    = $this->makeArmadaVendor($vendor->id_vendor, ['nopol' => 'B 4500 GD', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);

        $idKlien = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(6), 'nama_klien' => 'Klien Guard Hapus', 'dibuat_pada' => now(),
        ]);
        $idProyek = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Guard Hapus', 'dibuat_pada' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('penugasan')->insert([
            'id_penugasan' => (string) Str::uuid(), 'id_proyek' => $idProyek,
            'sumber' => 'vendor', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor' => $unit->id_armada_vendor,
            'status' => 'aktif', 'tanggal_tugas' => now()->toDateString(), 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/armada-vendor/{$unit->id_armada_vendor}")->assertStatus(422);
        $this->assertNull($unit->fresh()->dihapus_pada);

        \Illuminate\Support\Facades\DB::table('penugasan')->update(['status' => 'selesai']);
        $this->deleteJson("/api/armada-vendor/{$unit->id_armada_vendor}")->assertStatus(200);
        $this->assertNotNull($unit->fresh()->dihapus_pada);
    }

    public function test_timpa_unit_yang_dipakai_penugasan_aktif_tidak_terhapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $dipakai = $this->makeArmadaVendor($vendor->id_vendor, ['nopol' => 'B 5000 AK', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);
        $bebas   = $this->makeArmadaVendor($vendor->id_vendor, ['nopol' => 'B 6000 AK', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);

        $idKlien = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(6), 'nama_klien' => 'Klien Guard', 'dibuat_pada' => now(),
        ]);
        $idProyek = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek Guard', 'dibuat_pada' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('penugasan')->insert([
            'id_penugasan' => (string) Str::uuid(), 'id_proyek' => $idProyek,
            'sumber' => 'vendor', 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor' => $dipakai->id_armada_vendor,
            'status' => 'aktif', 'tanggal_tugas' => now()->toDateString(), 'dibuat_pada' => now(),
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['', 'B 9000 AK', '', '', '', '', '', '2027-06-01', '2027-06-02'],
        ], self::HEADINGS_UNIT);

        $res = $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/timpa-unit", ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.ditambah', 1)
            ->assertJsonPath('data.dihapus', 1)
            ->assertJsonPath('data.gagal.0.label', 'B 5000 AK');

        $this->assertNull($dipakai->fresh()->dihapus_pada);
        $this->assertNotNull($bebas->fresh()->dihapus_pada);
    }

    public function test_timpa_unit_nopol_kontrak_lain_masuk_daftar_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $lain    = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $this->makeArmadaVendor($vendor->id_vendor, ['nopol' => 'B 7777 LN', 'id_kontrak_vendor' => $lain->id_kontrak_vendor]);

        $file = $this->makeXlsxUploadedFile([
            ['', 'B 7777 LN', '', '', '', '', '', '2027-06-01', '2027-06-02'],
        ], self::HEADINGS_UNIT);

        $res = $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/timpa-unit", ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.ditambah', 0)
            ->assertJsonPath('data.gagal.0.label', 'B 7777 LN');

        $this->assertDatabaseMissing('armada_vendor', [
            'nopol'             => 'B 7777 LN',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
        ]);
    }

    public function test_timpa_supir_kontrak_unit_only_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');

        $file = $this->makeXlsxUploadedFile([
            ['', 'Supir Timpa', '0812', 'SIM-TIMPA-1', ''],
        ], self::HEADINGS_SUPIR);

        $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/timpa-supir", ['file' => $file])
            ->assertStatus(422);
    }

    public function test_timpa_supir_sinkron_dengan_kunci_no_sim(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');

        \Illuminate\Support\Facades\DB::table('supir_vendor')->insert([
            ['id_supir_vendor' => (string) Str::uuid(), 'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor, 'nama' => 'Lama Tetap', 'no_sim' => 'SIM-TETAP', 'dibuat_pada' => now()],
            ['id_supir_vendor' => (string) Str::uuid(), 'id_vendor' => $vendor->id_vendor, 'id_kontrak_vendor' => $kontrak->id_kontrak_vendor, 'nama' => 'Lama Hilang', 'no_sim' => 'SIM-HILANG', 'dibuat_pada' => now()],
        ]);

        $file = $this->makeXlsxUploadedFile([
            ['', 'Nama Baru', '0813', 'SIM-TETAP', ''],
            ['', 'Supir Tambahan', '0814', 'SIM-BARU', ''],
        ], self::HEADINGS_SUPIR);

        $res = $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/timpa-supir", ['file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.ditambah', 1)
            ->assertJsonPath('data.diperbarui', 1)
            ->assertJsonPath('data.dihapus', 1);

        $this->assertDatabaseHas('supir_vendor', ['no_sim' => 'SIM-TETAP', 'nama' => 'Nama Baru', 'dihapus_pada' => null]);
        $this->assertDatabaseHas('supir_vendor', ['no_sim' => 'SIM-BARU', 'dihapus_pada' => null]);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('supir_vendor')->where('no_sim', 'SIM-HILANG')->value('dihapus_pada'));
    }

    public function test_parse_unit_file_valid_mengembalikan_baris_sesuai_payload_unit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisKendaraan('Tronton');

        $file = $this->makeXlsxUploadedFile([
            ['VDR-001', 'B 1111 PRS', 'Hino', 'Dump Truck', 'Tronton', '20 ton', 2021, '2027-03-01', '2026-12-15'],
            ['', 'B 2222 PRS', '', '', '', '', '', '2027-06-01', '2027-06-02'],
        ], self::HEADINGS_UNIT);

        $res = $this->postJson('/api/kontrak-vendor/parse-unit', ['file' => $file]);

        $res->assertStatus(200);
        $this->assertSame([], $res->json('data.baris_gagal'));

        $valid = $res->json('data.baris_valid');
        $this->assertCount(2, $valid);
        $this->assertSame(
            ['nopol', 'merk', 'jenis', 'id_jenis_kendaraan', 'kapasitas', 'tahun', 'masa_berlaku_stnk', 'masa_berlaku_kir'],
            array_keys($valid[0])
        );
        $this->assertSame('B 1111 PRS', $valid[0]['nopol']);
        $this->assertSame('Hino', $valid[0]['merk']);
        $this->assertSame($idJenis, $valid[0]['id_jenis_kendaraan']);
        $this->assertSame(2021, $valid[0]['tahun']);
        $this->assertSame('2027-03-01', $valid[0]['masa_berlaku_stnk']);
        $this->assertSame('2026-12-15', $valid[0]['masa_berlaku_kir']);
        $this->assertSame('B 2222 PRS', $valid[1]['nopol']);
        $this->assertNull($valid[1]['id_jenis_kendaraan']);
    }

    public function test_parse_unit_baris_tanpa_nopol_masuk_baris_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['', 'B 3333 PRS', '', '', '', '', '', '2027-06-01', '2027-06-02'],
            ['VDR-001', '', 'Hino', '', '', '', '', '', ''],
        ], self::HEADINGS_UNIT);

        $res = $this->postJson('/api/kontrak-vendor/parse-unit', ['file' => $file]);

        $res->assertStatus(200);
        $valid = $res->json('data.baris_valid');
        $this->assertCount(1, $valid);
        $this->assertSame('B 3333 PRS', $valid[0]['nopol']);

        $gagal = $res->json('data.baris_gagal');
        $this->assertCount(1, $gagal);
        $this->assertSame(3, $gagal[0]['baris']);
        $this->assertSame('Nopol wajib diisi', $gagal[0]['alasan']);
    }

    public function test_parse_supir_file_valid_dan_baris_tanpa_nama_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['VDR-001', 'Joko Parse', '081234567890', 'SIM-PRS-1', '2027-06-30'],
            ['VDR-001', '', '081298765432', 'SIM-PRS-2', ''],
        ], self::HEADINGS_SUPIR);

        $res = $this->postJson('/api/kontrak-vendor/parse-supir', ['file' => $file]);

        $res->assertStatus(200);
        $valid = $res->json('data.baris_valid');
        $this->assertCount(1, $valid);
        $this->assertSame(
            ['nama', 'telepon', 'no_sim', 'masa_berlaku_sim'],
            array_keys($valid[0])
        );
        $this->assertSame('Joko Parse', $valid[0]['nama']);
        $this->assertSame('081234567890', $valid[0]['telepon']);
        $this->assertSame('SIM-PRS-1', $valid[0]['no_sim']);
        $this->assertSame('2027-06-30', $valid[0]['masa_berlaku_sim']);

        $gagal = $res->json('data.baris_gagal');
        $this->assertCount(1, $gagal);
        $this->assertSame(3, $gagal[0]['baris']);
        $this->assertSame('Nama wajib diisi', $gagal[0]['alasan']);
    }

    public function test_parse_file_bukan_excel_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/kontrak-vendor/parse-unit', ['file' => $this->makeTxtUploadedFile()])
            ->assertStatus(422);
        $this->postJson('/api/kontrak-vendor/parse-supir', ['file' => $this->makeTxtUploadedFile()])
            ->assertStatus(422);
    }

    public function test_parse_tidak_menulis_apapun_ke_db(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $this->makeArmadaVendor($vendor->id_vendor, ['nopol' => 'B 9999 ADA', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02']);

        $fileUnit = $this->makeXlsxUploadedFile([
            ['VDR-001', 'B 9999 ADA', '', '', '', '', '', '2027-06-01', '2027-06-02'],
            ['VDR-001', 'B 5555 PRS', '', '', '', '', '', '2027-06-01', '2027-06-02'],
        ], self::HEADINGS_UNIT);
        $resUnit = $this->postJson('/api/kontrak-vendor/parse-unit', ['file' => $fileUnit]);
        $resUnit->assertStatus(200);
        $this->assertCount(2, $resUnit->json('data.baris_valid'));
        $this->assertSame([], $resUnit->json('data.baris_gagal'));

        $fileSupir = $this->makeXlsxUploadedFile([
            ['VDR-001', 'Joko Tanpa Tulis', '0811', 'SIM-PRS-9', ''],
        ], self::HEADINGS_SUPIR);
        $this->postJson('/api/kontrak-vendor/parse-supir', ['file' => $fileSupir])
            ->assertStatus(200);

        $this->assertDatabaseCount('armada_vendor', 1);
        $this->assertDatabaseCount('supir_vendor', 0);
        $this->assertDatabaseCount('kontrak_vendor', 0);
    }

    public function test_komposit_unit_only_dengan_unit_membuat_armada_vendor_tertaut(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
            'unit'      => [
                ['nopol' => 'B 1111 KVP', 'merk' => 'Hino', 'jenis' => 'Tronton', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02'],
                ['nopol' => 'B 2222 KVP', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02'],
            ],
        ]);

        $res->assertStatus(201);
        $idKontrak = $res->json('data.id_kontrak_vendor');

        $this->assertDatabaseHas('armada_vendor', [
            'nopol'             => 'B 1111 KVP',
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $idKontrak,
            'merk'              => 'Hino',
        ]);
        $this->assertDatabaseHas('armada_vendor', [
            'nopol'             => 'B 2222 KVP',
            'id_kontrak_vendor' => $idKontrak,
        ]);
    }

    public function test_komposit_unit_driver_dengan_unit_dan_supir_tertaut(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_driver',
            'unit'      => [['nopol' => 'B 3333 KVP', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02']],
            'supir'     => [['nama' => 'Joko Paket', 'telepon' => '0811', 'no_sim' => 'SIM-PKT-1']],
        ]);

        $res->assertStatus(201);
        $idKontrak = $res->json('data.id_kontrak_vendor');

        $this->assertDatabaseHas('armada_vendor', [
            'nopol'             => 'B 3333 KVP',
            'id_kontrak_vendor' => $idKontrak,
        ]);
        $this->assertDatabaseHas('supir_vendor', [
            'nama'              => 'Joko Paket',
            'no_sim'            => 'SIM-PKT-1',
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $idKontrak,
        ]);
    }

    public function test_komposit_unit_only_dengan_supir_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
            'supir'     => [['nama' => 'Joko Salah Mekanisme']],
        ])->assertStatus(422);

        $this->assertDatabaseCount('kontrak_vendor', 0);
        $this->assertDatabaseCount('supir_vendor', 0);
    }

    public function test_nopol_duplikat_atau_sudah_terdaftar_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $this->makeArmadaVendor($vendor->id_vendor, ['nopol' => 'B 9999 ADA', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02']);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
            'unit'      => [['nopol' => 'B 4444 KVP', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02'], ['nopol' => 'b 4444 kvp', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02']],
        ])->assertStatus(422);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
            'unit'      => [['nopol' => 'B 9999 ADA', 'masa_berlaku_stnk' => '2027-06-01', 'masa_berlaku_kir' => '2027-06-02']],
        ])->assertStatus(422);

        $this->assertDatabaseCount('kontrak_vendor', 0);
        $this->assertSame(1, ArmadaVendorModel::count());
    }

    public function test_salin_dari_kontrak_memindahkan_tautan_unit_dan_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrakLama = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $unitLama  = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakLama->id_kontrak_vendor]);
        $supirLama = $this->makeSupirVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakLama->id_kontrak_vendor]);
        $unitUmum  = $this->makeArmadaVendor($vendor->id_vendor);

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'          => $vendor->id_vendor,
            'mekanisme'          => 'unit_driver',
            'salin_dari_kontrak' => $kontrakLama->id_kontrak_vendor,
        ]);

        $res->assertStatus(201);
        $idKontrakBaru = $res->json('data.id_kontrak_vendor');

        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor'  => $unitLama->id_armada_vendor,
            'id_kontrak_vendor' => $idKontrakBaru,
        ]);
        $this->assertDatabaseHas('supir_vendor', [
            'id_supir_vendor'   => $supirLama->id_supir_vendor,
            'id_kontrak_vendor' => $idKontrakBaru,
        ]);
        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor'  => $unitUmum->id_armada_vendor,
            'id_kontrak_vendor' => null,
        ]);
    }

    public function test_salin_dari_kontrak_vendor_lain_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor     = $this->makeVendor();
        $vendorLain = $this->makeVendor();
        $kontrakVendorLain = $this->makeKontrak($vendorLain->id_vendor, 'unit_driver');

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'          => $vendor->id_vendor,
            'mekanisme'          => 'unit_driver',
            'salin_dari_kontrak' => $kontrakVendorLain->id_kontrak_vendor,
        ])->assertStatus(422);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Paket',
            'dibuat_pada'   => now(),
        ]);
        $vendorPerusahaanLain = $this->makeVendor($idPerusahaanLain);
        $kontrakPerusahaanLain = KontrakVendorModel::create([
            'id_perusahaan' => $idPerusahaanLain,
            'id_vendor'     => $vendorPerusahaanLain->id_vendor,
            'mekanisme'     => 'unit_driver',
        ]);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'          => $vendor->id_vendor,
            'mekanisme'          => 'unit_driver',
            'salin_dari_kontrak' => $kontrakPerusahaanLain->id_kontrak_vendor,
        ])->assertStatus(404);
    }

    public function test_opsi_board_memakai_kontrak_unit_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $kontrakPaket = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $unitPaket = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakPaket->id_kontrak_vendor]);
        $unitUmum  = $this->makeArmadaVendor($vendor->id_vendor);

        $res = $this->getJson('/api/penugasan/board?dari=2026-09-01&sampai=2026-09-02')
            ->assertStatus(200);
        $units = collect($res->json('data.units'));

        $barisPaket = $units->firstWhere('id_armada_vendor', $unitPaket->id_armada_vendor);
        $this->assertNotNull($barisPaket);
        $this->assertSame('unit_driver', $barisPaket['mekanisme']);
        $this->assertSame($kontrakPaket->id_kontrak_vendor, $barisPaket['id_kontrak_vendor']);
        $this->assertSame($kontrakPaket->id_kontrak_vendor, $barisPaket['id_kontrak_vendor_unit']);
        $this->assertFalse($barisPaket['kontrak_habis']);

        $barisUmum = $units->firstWhere('id_armada_vendor', $unitUmum->id_armada_vendor);
        $this->assertNotNull($barisUmum);
        $this->assertSame('unit_only', $barisUmum['mekanisme']);
        $this->assertNull($barisUmum['id_kontrak_vendor_unit']);
    }

    public function test_opsi_board_flag_kontrak_habis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrakLewat = $this->makeKontrak($vendor->id_vendor, 'unit_driver', [
            'tanggal_mulai'   => '2025-01-01',
            'tanggal_selesai' => '2025-12-31',
        ]);
        $kontrakNonaktif = $this->makeKontrak($vendor->id_vendor, 'unit_driver', [
            'status' => 'nonaktif',
        ]);
        $kontrakJalan = $this->makeKontrak($vendor->id_vendor, 'unit_driver', [
            'tanggal_selesai' => now()->addMonth()->toDateString(),
        ]);

        $unitLewat    = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakLewat->id_kontrak_vendor]);
        $unitNonaktif = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakNonaktif->id_kontrak_vendor]);
        $unitJalan    = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakJalan->id_kontrak_vendor]);

        $units = collect($this->getJson('/api/penugasan/board?dari=2026-09-01&sampai=2026-09-02')
            ->assertStatus(200)
            ->json('data.units'));

        $this->assertTrue($units->firstWhere('id_armada_vendor', $unitLewat->id_armada_vendor)['kontrak_habis']);
        $this->assertTrue($units->firstWhere('id_armada_vendor', $unitNonaktif->id_armada_vendor)['kontrak_habis']);
        $this->assertFalse($units->firstWhere('id_armada_vendor', $unitJalan->id_armada_vendor)['kontrak_habis']);
    }

    public function test_opsi_unit_only_memakai_kontrak_unit_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrakUnitOnly = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $kontrakPaket    = $this->makeKontrak($vendor->id_vendor, 'unit_driver');

        $unitPaket    = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakPaket->id_kontrak_vendor]);
        $unitUnitOnly = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakUnitOnly->id_kontrak_vendor]);

        $res = $this->getJson('/api/penugasan/opsi-armada-vendor')->assertStatus(200);
        $data = collect($res->json('data'));

        $this->assertNull($data->firstWhere('id_armada_vendor', $unitPaket->id_armada_vendor));

        $barisUnitOnly = $data->firstWhere('id_armada_vendor', $unitUnitOnly->id_armada_vendor);
        $this->assertNotNull($barisUnitOnly);
        $this->assertSame($kontrakUnitOnly->id_kontrak_vendor, $barisUnitOnly['id_kontrak_vendor']);
        $this->assertFalse($barisUnitOnly['kontrak_habis']);
    }

    public function test_assign_harian_dengan_kontrak_habis_tetap_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->makeProyekDenganRute();
        $vendor = $this->makeVendor();
        $kontrakHabis = $this->makeKontrak($vendor->id_vendor, 'unit_only', [
            'status'          => 'nonaktif',
            'tanggal_selesai' => '2025-12-31',
        ]);
        $unit = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakHabis->id_kontrak_vendor]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Kontrak Habis', 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/penugasan/harian', [
            'tanggal'          => '2026-09-10',
            'id_armada_vendor' => $unit->id_armada_vendor,
            'id_supir'         => $idSupir,
            'id_proyek'        => $ctx['id_proyek'],
            'id_rute'          => $ctx['id_rute'],
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->assertDatabaseHas('penugasan', [
            'id_armada_vendor'  => $unit->id_armada_vendor,
            'id_kontrak_vendor' => $kontrakHabis->id_kontrak_vendor,
            'id_supir'          => $idSupir,
            'sumber'            => 'vendor',
        ]);
    }

    public function test_assign_harian_supir_vendor_beda_kontrak_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->makeProyekDenganRute();
        $vendor = $this->makeVendor();
        $kontrakA = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $kontrakB = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $unit = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakA->id_kontrak_vendor]);
        $supirBedaKontrak = $this->makeSupirVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakB->id_kontrak_vendor]);
        $supirSatuKontrak = $this->makeSupirVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakA->id_kontrak_vendor]);

        $payload = fn (string $idSupirVendor, string $tanggal) => [
            'tanggal'          => $tanggal,
            'id_armada_vendor' => $unit->id_armada_vendor,
            'id_supir_vendor'  => $idSupirVendor,
            'id_proyek'        => $ctx['id_proyek'],
            'id_rute'          => $ctx['id_rute'],
        ];

        $this->postJson('/api/penugasan/harian', $payload($supirBedaKontrak->id_supir_vendor, '2026-09-11'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Supir vendor bukan bagian dari kontrak unit ini');

        $this->postJson('/api/penugasan/harian', $payload($supirSatuKontrak->id_supir_vendor, '2026-09-11'))
            ->assertStatus(200)->assertJsonPath('data.sukses', 1);
    }

    public function test_assign_harian_supir_vendor_tanpa_kontrak_tetap_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->makeProyekDenganRute();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $unit = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);
        $supirUmum = $this->makeSupirVendor($vendor->id_vendor);

        $this->postJson('/api/penugasan/harian', [
            'tanggal'          => '2026-09-12',
            'id_armada_vendor' => $unit->id_armada_vendor,
            'id_supir_vendor'  => $supirUmum->id_supir_vendor,
            'id_proyek'        => $ctx['id_proyek'],
            'id_rute'          => $ctx['id_rute'],
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);
    }

    public function test_create_penugasan_supir_vendor_beda_kontrak_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->makeProyekDenganRute();
        $vendor = $this->makeVendor();
        $kontrakA = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $kontrakB = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $unit = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakA->id_kontrak_vendor]);
        $supirBedaKontrak = $this->makeSupirVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrakB->id_kontrak_vendor]);

        $this->postJson('/api/penugasan', [
            'id_proyek'         => $ctx['id_proyek'],
            'id_rute'           => $ctx['id_rute'],
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrakA->id_kontrak_vendor,
            'id_armada_vendor'  => $unit->id_armada_vendor,
            'id_supir_vendor'   => $supirBedaKontrak->id_supir_vendor,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Supir vendor bukan bagian dari kontrak unit ini');
    }

    public function test_listing_supir_vendor_mengekspos_id_kontrak_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $supir = $this->makeSupirVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);

        $res = $this->getJson('/api/supir-vendor?id_vendor=' . $vendor->id_vendor)
            ->assertStatus(200);

        $baris = collect($res->json('data'))->firstWhere('id_supir_vendor', $supir->id_supir_vendor);
        $this->assertNotNull($baris);
        $this->assertSame($kontrak->id_kontrak_vendor, $baris['id_kontrak_vendor']);
    }
    public function test_delete_kontrak_dengan_riwayat_penugasan_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->makeProyekDenganRute();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_only');
        $unit = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Delete Guard', 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/penugasan/harian', [
            'tanggal'          => '2026-09-15',
            'id_armada_vendor' => $unit->id_armada_vendor,
            'id_supir'         => $idSupir,
            'id_proyek'        => $ctx['id_proyek'],
            'id_rute'          => $ctx['id_rute'],
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->deleteJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}")
            ->assertStatus(422);

        $this->assertDatabaseHas('kontrak_vendor', [
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'dihapus_pada'      => null,
        ]);
        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor'  => $unit->id_armada_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
        ]);
    }

    public function test_delete_kontrak_bersih_melepas_tautan_unit_dan_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor, 'unit_driver');
        $unit = $this->makeArmadaVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);
        $supir = $this->makeSupirVendor($vendor->id_vendor, ['id_kontrak_vendor' => $kontrak->id_kontrak_vendor]);

        $this->deleteJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('kontrak_vendor', [
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'dihapus_pada'      => null,
        ]);
        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor'  => $unit->id_armada_vendor,
            'id_kontrak_vendor' => null,
            'dihapus_pada'      => null,
        ]);
        $this->assertDatabaseHas('supir_vendor', [
            'id_supir_vendor'   => $supir->id_supir_vendor,
            'id_kontrak_vendor' => null,
            'dihapus_pada'      => null,
        ]);
    }

}
