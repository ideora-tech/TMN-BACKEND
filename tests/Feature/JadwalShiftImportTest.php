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

    private function makeJadwal(string $idProyek, string $idShift, string $idSupir, string $tanggal): string
    {
        $id = (string) Str::uuid();
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => $id,
            'id_proyek'       => $idProyek,
            'id_shift'        => $idShift,
            'id_supir'        => $idSupir,
            'tanggal'         => $tanggal,
            'dibuat_pada'     => now(),
        ]);
        return $id;
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

    public function test_import_dengan_baris_judul_di_atas_header_tetap_sukses(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Judul', 'SIM-JUDUL-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $this->makeShiftNamed('Pagi');

        $file = $this->buatFileMatriks([
            ['PROYEK IMPORT TEST', '', '', ''],
            ['PERIODE AGUSTUS 2026', '', '', ''],
            ['No SIM', 'Nama Supir', 'Shift', '2026-08-10'],
            ['SIM-JUDUL-1', 'Supir Judul', 'Pagi', 'H'],
        ]);

        $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->assertDatabaseHas('jadwal_shift', ['id_supir' => $idSupir, 'tanggal' => '2026-08-10']);
    }

    public function test_import_header_tanggal_serial_excel_terbaca(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Serial', 'SIM-SERIAL-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $this->makeShiftNamed('Pagi');

        $serial = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2026-08-15'));
        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', $serial],
            ['SIM-SERIAL-1', 'Supir Serial', 'Pagi', 'H'],
        ]);

        $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->assertDatabaseHas('jadwal_shift', ['id_supir' => $idSupir, 'tanggal' => '2026-08-15']);
    }

    public function test_template_bergaya_tetap_bisa_diimport_balik(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Roundtrip', 'SIM-RT-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);

        $res = $this->get("/api/v1/jadwal-shift/import/template?id_proyek={$proyek->id_proyek}&dari=2026-08-01&sampai=2026-08-31");
        $res->assertStatus(200);

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        copy($res->baseResponse->getFile()->getPathname(), $path);
        $file = new UploadedFile($path, 'template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 0)->assertJsonPath('data.gagal', []);

        $rows = \Maatwebsite\Excel\Facades\Excel::toArray(new \App\Modules\JadwalShift\Imports\JadwalShiftImport(), $file)[0];
        $this->assertSame('Proyek Import Test', trim((string) $rows[0][0]));
        $this->assertSame('PERIODE AGUSTUS 2026', trim((string) $rows[1][0]));
        $this->assertSame('No SIM', trim((string) $rows[2][0]));
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

    public function test_import_menimpa_jadwal_proyek_sama_shift_beda(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = ArmadaModel::create(['id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 222 TP', 'merk' => 'Hino']);
        $idSupir = $this->makeSupir('Supir Timpa', 'SIM-TIMPA-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'id_armada'     => $armada->id_armada,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $idShiftPagi  = $this->makeShiftNamed('Pagi');
        $idShiftSiang = $this->makeShiftNamed('Siang');
        $idLama = $this->makeJadwal($proyek->id_proyek, $idShiftPagi, $idSupir, '2026-08-10');

        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', '2026-08-10'],
            ['SIM-TIMPA-1', 'Supir Timpa', 'Siang', 'H'],
        ]);

        $res = $this->post('/api/v1/jadwal-shift/import', ['id_proyek' => $proyek->id_proyek, 'file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 0)
            ->assertJsonCount(1, 'data.ditimpa')
            ->assertJsonCount(0, 'data.gagal')
            ->assertJsonPath('data.ditimpa.0.tanggal', '2026-08-10')
            ->assertJsonPath('data.ditimpa.0.shift_lama', 'Pagi')
            ->assertJsonPath('data.ditimpa.0.shift_baru', 'Siang');

        $this->assertNotNull(DB::table('jadwal_shift')->where('id_jadwal_shift', $idLama)->value('dihapus_pada'));

        $baru = DB::table('jadwal_shift')
            ->where('id_supir', $idSupir)->where('tanggal', '2026-08-10')
            ->whereNull('dihapus_pada')->first();
        $this->assertNotNull($baru);
        $this->assertNotSame($idLama, $baru->id_jadwal_shift);
        $this->assertSame($idShiftSiang, $baru->id_shift);

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'  => $idSupir,
            'tanggal'   => '2026-08-10',
            'id_armada' => $armada->id_armada,
            'sumber'    => 'penugasan',
        ]);
    }

    public function test_import_shift_identik_dihitung_sukses_tanpa_perubahan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Identik', 'SIM-IDENTIK-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $idShiftPagi = $this->makeShiftNamed('Pagi');
        $idLama = $this->makeJadwal($proyek->id_proyek, $idShiftPagi, $idSupir, '2026-08-10');

        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', '2026-08-10'],
            ['SIM-IDENTIK-1', 'Supir Identik', 'Pagi', 'H'],
        ]);

        $res = $this->post('/api/v1/jadwal-shift/import', ['id_proyek' => $proyek->id_proyek, 'file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 1)
            ->assertJsonCount(0, 'data.ditimpa')
            ->assertJsonCount(0, 'data.gagal');

        $this->assertNull(DB::table('jadwal_shift')->where('id_jadwal_shift', $idLama)->value('dihapus_pada'));
        $this->assertSame(1, DB::table('jadwal_shift')->where('id_supir', $idSupir)->where('tanggal', '2026-08-10')->count());
    }

    public function test_import_konflik_lintas_proyek_tetap_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekTujuan = $this->makeProyek();
        $proyekLain   = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Lintas', 'SIM-LINTAS-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyekTujuan->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $idShiftPagi = $this->makeShiftNamed('Pagi');
        $this->makeShiftNamed('Siang');
        $idLama = $this->makeJadwal($proyekLain->id_proyek, $idShiftPagi, $idSupir, '2026-08-10');

        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', '2026-08-10'],
            ['SIM-LINTAS-1', 'Supir Lintas', 'Siang', 'H'],
        ]);

        $res = $this->post('/api/v1/jadwal-shift/import', ['id_proyek' => $proyekTujuan->id_proyek, 'file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 0)
            ->assertJsonCount(0, 'data.ditimpa')
            ->assertJsonCount(1, 'data.gagal');
        $this->assertStringContainsString('di proyek', $res->json('data.gagal.0.alasan'));

        $this->assertNull(DB::table('jadwal_shift')->where('id_jadwal_shift', $idLama)->value('dihapus_pada'));
    }

    public function test_import_tidak_menimpa_jadwal_hari_ini_bila_trip_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $hariIni = now()->toDateString();
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Trip', 'SIM-TRIP-1');
        $penugasan = PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => $hariIni,
        ]);
        $idShiftPagi = $this->makeShiftNamed('Pagi');
        $this->makeShiftNamed('Siang');
        $idLama = $this->makeJadwal($proyek->id_proyek, $idShiftPagi, $idSupir, $hariIni);

        $idJadwalBerangkat = (string) Str::uuid();
        DB::table('jadwal_keberangkatan')->insert([
            'id_jadwal'       => $idJadwalBerangkat,
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
            'dibuat_pada'     => now(),
        ]);
        DB::table('trip')->insert([
            'id_trip'     => (string) Str::uuid(),
            'id_jadwal'   => $idJadwalBerangkat,
            'status'      => 'berjalan',
            'dibuat_pada' => now(),
        ]);

        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', $hariIni],
            ['SIM-TRIP-1', 'Supir Trip', 'Siang', 'H'],
        ]);

        $res = $this->post('/api/v1/jadwal-shift/import', ['id_proyek' => $proyek->id_proyek, 'file' => $file]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 0)
            ->assertJsonCount(0, 'data.ditimpa')
            ->assertJsonCount(1, 'data.gagal');
        $this->assertStringContainsString('trip aktif', $res->json('data.gagal.0.alasan'));

        $this->assertNull(DB::table('jadwal_shift')->where('id_jadwal_shift', $idLama)->value('dihapus_pada'));
    }
}
