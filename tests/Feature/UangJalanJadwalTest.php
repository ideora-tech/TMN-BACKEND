<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengajuan uang jalan otomatis dari batch/import papan shift
 * (ArusKasService::buatPengajuanUangJalanJadwal + sinkronisasi berbasis
 * jadwal_shift.id_pengajuan) sudah dicabut — resolusi tarif & pembuatan
 * pengajuan uang jalan sekarang berbasis penugasan harian eksplisit (Task 5,
 * lihat PenugasanHarianTest). Test di sini murni regresi: memastikan papan
 * shift tidak lagi membuat pengajuan. Satu test dipertahankan lewat fixture
 * DB langsung untuk jalur baca historis (`jadwal_shift.id_pengajuan` sengaja
 * DIBIARKAN) yang masih hidup di ArusKasRepository::findPengajuanPeriodeUntukTrip.
 */
class UangJalanJadwalTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Uang Jalan Jadwal',
        ]);
    }

    private function makeSupir(string $nama = 'Budi'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeShift(): string
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Pagi', 'jam_mulai' => '08:00:00', 'jam_selesai' => '16:00:00',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeRute(): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Jakarta - Bandung',
            'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeProyekRute(string $idProyek, string $idRute, float $uangJalan): void
    {
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $idProyek, 'id_rute' => $idRute,
            'uang_jalan' => $uangJalan, 'dibuat_pada' => now(),
        ]);
    }

    private function makePenugasan(string $idProyek, string $idSupir, ?string $idRute): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek' => $idProyek, 'id_supir' => $idSupir,
            'id_rute' => $idRute, 'status' => 'aktif',
        ]);
    }

    private function makeSupirProyek(string $idProyek, string $idSupir): void
    {
        DB::table('supir_proyek')->insert([
            'id_supir_proyek' => (string) Str::uuid(),
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_proyek'       => $idProyek,
            'id_supir'        => $idSupir,
            'dibuat_pada'     => now(),
        ]);
    }

    private function bikinFileImport(string $noSim, string $namaShift, string $tanggal): \Illuminate\Http\UploadedFile
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['No SIM', 'Nama', 'Shift', $tanggal], null, 'A1');
        $sheet->fromArray([$noSim, 'X', $namaShift, 'H'], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'jdw') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        return new \Illuminate\Http\UploadedFile($path, 'jadwal.xlsx', null, null, true);
    }

    public function test_batch_shift_tidak_membuat_pengajuan_uang_jalan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $supirA = $this->makeSupir('Budi Jadwal');
        $supirB = $this->makeSupir('Andi Jadwal');
        $this->makePenugasan($proyek->id_proyek, $supirA, $rute);
        $this->makePenugasan($proyek->id_proyek, $supirB, $rute);
        $this->makeSupirProyek($proyek->id_proyek, $supirA);
        $this->makeSupirProyek($proyek->id_proyek, $supirB);
        $shift = $this->makeShift();

        $res = $this->postJson('/api/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-03',
            'supir' => [$supirA, $supirB],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 6);
        $this->assertArrayNotHasKey('peringatan', $res->json('data'));
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
        $this->assertSame(6, DB::table('jadwal_shift')->whereNull('id_pengajuan')->whereNull('dihapus_pada')->count());
    }

    public function test_import_matriks_tidak_membuat_pengajuan_uang_jalan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 100000.0);
        $supir = $this->makeSupir('Hana Import Baru');
        $this->makePenugasan($proyek->id_proyek, $supir, $rute);
        $this->makeSupirProyek($proyek->id_proyek, $supir);
        $this->makeShift();
        $noSim = (string) DB::table('supir')->where('id_supir', $supir)->value('no_sim');

        $hasil = app(\App\Modules\JadwalShift\JadwalShiftService::class)->importMatriks(
            $this->bikinFileImport($noSim, 'Pagi', '2026-09-05'),
            (string) $proyek->id_proyek,
            self::PERUSAHAAN_ID,
        );

        $this->assertSame(1, $hasil['sukses']);
        $this->assertArrayNotHasKey('peringatan', $hasil);
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_detail_trip_menampilkan_pengajuan_periode_dari_jadwal_historis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek    = $this->makeProyek();
        $supir     = $this->makeSupir('Ika Card Trip');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir, null);
        $tanggal   = '2026-09-01';

        $jadwalKeberangkatan = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => $tanggal . ' 07:00:00',
        ]);
        $trip = TripModel::create([
            'id_jadwal'      => $jadwalKeberangkatan->id_jadwal,
            'status'         => 'selesai',
            'waktu_checkin'  => $tanggal . ' 07:18:00',
            'waktu_checkout' => $tanggal . ' 11:30:00',
        ]);

        // Fixture langsung ke DB — mensimulasikan data historis dari sebelum
        // pengajuan-dari-shift dicabut: jadwal_shift.id_pengajuan menaut ke
        // pengajuan_pengeluaran, tanpa melalui alur batch yang sudah dihapus.
        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan'      => $idPengajuan,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_supir'          => $supir,
            'id_proyek'         => $proyek->id_proyek,
            'periode_dari'      => $tanggal,
            'periode_sampai'    => $tanggal,
            'tarif_per_hari'    => 150000,
            'nomor_pengajuan'   => 'PP-TEST-' . Str::random(6),
            'kategori'          => 'uang_jalan',
            'nominal'           => 150000,
            'tanggal_pengajuan' => $tanggal,
            'penerima'          => 'Ika Card Trip',
            'keterangan'        => 'Uang jalan Ika Card Trip',
            'status'            => 'diajukan',
            'dibuat_pada'       => now(),
        ]);
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(),
            'id_proyek'       => $proyek->id_proyek,
            'id_shift'        => $this->makeShift(),
            'id_supir'        => $supir,
            'tanggal'         => $tanggal,
            'id_pengajuan'    => $idPengajuan,
            'dibuat_pada'     => now(),
        ]);

        $res = $this->getJson("/api/trip/{$trip->id_trip}");
        $res->assertStatus(200)
            ->assertJsonPath('data.pengajuan_uang_jalan.id_pengajuan', $idPengajuan)
            ->assertJsonPath('data.pengajuan_uang_jalan.periode.jumlah_hari', 1)
            ->assertJsonPath('data.pengajuan_uang_jalan.periode.tarif_per_hari', 150000);
    }
}
