<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Tests\TestCase;

class PenugasanParseUnitTest extends TestCase
{
    use RefreshDatabase;

    private function buatFileExcel(array $rows): UploadedFile
    {
        $export = new class($rows) implements FromArray {
            public function __construct(private readonly array $rows) {}
            public function array(): array
            {
                return array_merge([['nopol', 'nama_supir', 'kode_rute']], $this->rows);
            }
        };
        $path = sys_get_temp_dir() . '/parse-unit-' . Str::random(8) . '.xlsx';
        Excel::store($export, basename($path), null, \Maatwebsite\Excel\Excel::XLSX);
        $stored = storage_path('app/' . basename($path));

        return new UploadedFile($stored, 'unit.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_parse_unit_mencocokkan_nopol_supir_dan_rute_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B 7001 PU', 'status' => 'tersedia', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Budi Parse', 'no_sim' => 'SIM-' . Str::random(6), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-PU-1', 'nama_proyek' => 'Proyek Parse', 'dibuat_pada' => now(),
        ]);
        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $idRute, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RUT-PU-1', 'nama_rute' => 'Bekasi - Jakarta', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $idProyek, 'id_rute' => $idRute, 'estimasi_ritase' => 1, 'dibuat_pada' => now(),
        ]);

        $idArmada2 = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada2, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B 7002 PU', 'status' => 'tersedia', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('supir')->insert([
            'id_supir' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Siti Parse', 'no_sim' => 'SIM-' . Str::random(6), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $file = $this->buatFileExcel([
            ['b 7001 pu', 'budi parse', 'rut-pu-1'],
            ['B 9999 XX', 'Budi Parse', 'RUT-PU-1'],
            ['B 7002 PU', 'Siti Parse', 'RUT-LAIN'],
        ]);

        $res = $this->post('/api/penugasan/parse-unit', ['file' => $file, 'id_proyek' => $idProyek]);

        $res->assertStatus(200)
            ->assertJsonPath('data.baris_valid.0.id_armada', $idArmada)
            ->assertJsonPath('data.baris_valid.0.id_supir', $idSupir)
            ->assertJsonPath('data.baris_valid.0.id_rute', $idRute);
        $this->assertCount(1, $res->json('data.baris_valid'));
        $this->assertCount(2, $res->json('data.baris_gagal'));
        $this->assertStringContainsString('kode terdaftar: RUT-PU-1', $res->json('data.baris_gagal.1.alasan'));

        $fileNama = $this->buatFileExcel([
            ['B 7001 PU', 'Budi Parse', 'bekasi - jakarta'],
        ]);
        $resNama = $this->post('/api/penugasan/parse-unit', ['file' => $fileNama, 'id_proyek' => $idProyek]);
        $resNama->assertStatus(200)->assertJsonPath('data.baris_valid.0.id_rute', $idRute);
    }

    public function test_template_unit_bisa_diunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->get('/api/penugasan/template-unit')->assertStatus(200);
    }
}
