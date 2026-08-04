<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class JadwalShiftImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Import Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Import Test',
        ]);
    }

    private function makeSupir(string $nama, string $noSim, ?string $idArmadaDefault = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'          => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_armada_default' => $idArmadaDefault,
            'nama'              => $nama,
            'no_sim'            => $noSim,
            'jenis_sim'         => 'B1',
            'status'            => 'aktif',
            'dibuat_pada'       => now(),
        ]);
        return $id;
    }

    private function makeShiftNamed(string $nama): string
    {
        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'      => $idShift,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'jam_mulai'     => '06:00:00',
            'jam_selesai'   => '14:00:00',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $idShift;
    }

    private function buatFileMatriks(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'jadwal') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'jadwal-shift.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_template_import_bisa_diunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $this->get("/api/v1/jadwal-shift/import/template?id_proyek={$proyek->id_proyek}&dari=2026-08-01&sampai=2026-08-31")
            ->assertStatus(200);
    }

    public function test_import_matriks_membuat_jadwal_dan_alokasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = ArmadaModel::create(['id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 111 IM', 'merk' => 'Hino']);
        $idSupir = $this->makeSupir('Supir Import', 'SIM-IMPORT-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'id_armada'     => $armada->id_armada,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $this->makeShiftNamed('Pagi');

        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', '2026-08-10', '2026-08-11', '2026-08-12'],
            ['SIM-IMPORT-1', 'Supir Import', 'Pagi', 'H', 'L', 'H'],
        ]);

        $res = $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 2)
            ->assertJsonCount(0, 'data.gagal');

        $this->assertDatabaseHas('jadwal_shift', ['id_supir' => $idSupir, 'tanggal' => '2026-08-10']);
        $this->assertDatabaseMissing('jadwal_shift', ['id_supir' => $idSupir, 'tanggal' => '2026-08-11']);
        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'  => $idSupir,
            'tanggal'   => '2026-08-10',
            'id_armada' => $armada->id_armada,
            'sumber'    => 'penugasan',
        ]);
    }

    public function test_import_baris_bermasalah_dilaporkan_tanpa_menggagalkan_lainnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Valid', 'SIM-VALID-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $this->makeShiftNamed('Pagi');

        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', '2026-08-10'],
            ['SIM-VALID-1', 'Supir Valid', 'Pagi', 'H'],
            ['SIM-TIDAK-ADA', 'Siapa Ini', 'Pagi', 'H'],
            ['SIM-VALID-1', 'Supir Valid', 'Shift Aneh', 'H'],
        ]);

        $res = $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 1);
        $this->assertCount(2, $res->json('data.gagal'));
    }
}
