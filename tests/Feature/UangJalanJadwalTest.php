<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

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

    private function makeJenisKendaraan(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeProyekRute(string $idProyek, string $idRute, ?float $uangJalan, ?string $idJenis = null): void
    {
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $idProyek, 'id_rute' => $idRute,
            'id_jenis_kendaraan' => $idJenis, 'uang_jalan' => $uangJalan,
            'dibuat_pada' => now(),
        ]);
    }

    private function makePenugasan(string $idProyek, string $idSupir, ?string $idRute, ?string $idArmada = null): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek' => $idProyek, 'id_supir' => $idSupir,
            'id_rute' => $idRute, 'id_armada' => $idArmada, 'status' => 'aktif',
        ]);
    }

    public function test_batch_membuat_satu_pengajuan_per_supir_dengan_nominal_tarif_kali_hari(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $supirA = $this->makeSupir('Budi Jadwal');
        $supirB = $this->makeSupir('Andi Jadwal');
        $this->makePenugasan($proyek->id_proyek, $supirA, $rute);
        $this->makePenugasan($proyek->id_proyek, $supirB, $rute);
        $shift = $this->makeShift();

        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-03',
            'supir' => [$supirA, $supirB],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 6)->assertJsonPath('data.peringatan', []);

        $this->assertSame(2, DB::table('pengajuan_pengeluaran')->where('kategori', 'uang_jalan')->count());
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_supir' => $supirA, 'id_proyek' => $proyek->id_proyek,
            'kategori' => 'uang_jalan', 'nominal' => 450000,
            'tarif_per_hari' => 150000, 'periode_dari' => '2026-09-01',
            'periode_sampai' => '2026-09-03', 'penerima' => 'Budi Jadwal', 'status' => 'diajukan',
        ]);

        $idPengajuanA = DB::table('pengajuan_pengeluaran')->where('id_supir', $supirA)->value('id_pengajuan');
        $this->assertSame(3, DB::table('jadwal_shift')->where('id_pengajuan', $idPengajuanA)->count());

        $keterangan = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supirA)->value('keterangan');
        $this->assertStringContainsString('Budi Jadwal', $keterangan);
        $this->assertStringContainsString('3 hari', $keterangan);

        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_supir' => $supirB, 'id_proyek' => $proyek->id_proyek,
            'kategori' => 'uang_jalan', 'nominal' => 450000,
            'tarif_per_hari' => 150000, 'periode_dari' => '2026-09-01',
            'periode_sampai' => '2026-09-03', 'penerima' => 'Andi Jadwal', 'status' => 'diajukan',
        ]);

        $idPengajuanB = DB::table('pengajuan_pengeluaran')->where('id_supir', $supirB)->value('id_pengajuan');
        $this->assertNotSame($idPengajuanA, $idPengajuanB);
        $this->assertSame(3, DB::table('jadwal_shift')->where('id_pengajuan', $idPengajuanB)->count());
    }

    public function test_list_pengajuan_menampilkan_kolom_periode(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->batchSatuSupir(120000.0, '2026-09-10', '2026-09-12');

        $res = $this->getJson('/api/v1/arus-kas/pengajuan');
        $res->assertStatus(200);

        $baris = collect($res->json('data'))->firstWhere('id_pengajuan', $ctx['id_pengajuan']);
        $this->assertNotNull($baris);
        $this->assertSame('2026-09-10', $baris['periode_dari']);
        $this->assertSame('2026-09-12', $baris['periode_sampai']);
        $this->assertEquals(120000, $baris['tarif_per_hari']);
    }

    public function test_batch_tanpa_tarif_menyimpan_jadwal_dengan_peringatan_tanpa_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir  = $this->makeSupir('Cici Tanpa Tarif');
        $this->makePenugasan($proyek->id_proyek, $supir, null);
        $shift = $this->makeShift();

        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-02', 'supir' => [$supir],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 2);
        $this->assertStringContainsString('Cici Tanpa Tarif', $res->json('data.peringatan.0'));
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
        $this->assertSame(2, DB::table('jadwal_shift')->whereNull('id_pengajuan')->whereNull('dihapus_pada')->count());
    }

    public function test_batch_hari_duplikat_tidak_ikut_dihitung(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 100000.0);
        $supir = $this->makeSupir('Dodi Duplikat');
        $this->makePenugasan($proyek->id_proyek, $supir, $rute);
        $shift = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-09-01', 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1);

        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-02', 'supir' => [$supir],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 1);
        $this->assertCount(1, $res->json('data.gagal'));
        $this->assertSame(2, DB::table('pengajuan_pengeluaran')->count());
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_supir' => $supir, 'nominal' => 100000,
            'periode_dari' => '2026-09-02', 'periode_sampai' => '2026-09-02',
        ]);
    }

    public function test_tarif_prioritas_jenis_kendaraan_armada_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $jenisA = $this->makeJenisKendaraan('CDD');
        $jenisB = $this->makeJenisKendaraan('Tronton');
        $this->makeProyekRute($proyek->id_proyek, $rute, 100000.0, $jenisA);
        $this->makeProyekRute($proyek->id_proyek, $rute, 250000.0, $jenisB);
        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 1234 UJ',
            'merk' => 'Hino', 'id_jenis_kendaraan' => $jenisB,
        ])->id_armada;
        $supir = $this->makeSupir('Edo Tronton');
        $this->makePenugasan($proyek->id_proyek, $supir, $rute, $idArmada);
        $shift = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-09-01', 'supir' => [$supir],
        ])->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_supir' => $supir, 'tarif_per_hari' => 250000]);
    }

    private function batchSatuSupir(float $tarif = 100000.0, string $dari = '2026-09-01', string $sampai = '2026-09-03'): array
    {
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, $tarif);
        $supir = $this->makeSupir('Fani Sinkron');
        $this->makePenugasan($proyek->id_proyek, $supir, $rute);
        $shift = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => $dari, 'tanggal_sampai' => $sampai, 'supir' => [$supir],
        ])->assertStatus(200);

        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supir)->value('id_pengajuan');
        return ['id_pengajuan' => $idPengajuan, 'id_supir' => $supir];
    }

    private function idJadwalPadaTanggal(string $idSupir, string $tanggal): string
    {
        return (string) DB::table('jadwal_shift')
            ->where('id_supir', $idSupir)->where('tanggal', $tanggal)
            ->whereNull('dihapus_pada')->value('id_jadwal_shift');
    }

    public function test_hapus_satu_hari_menurunkan_nominal_dan_periode(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->batchSatuSupir();

        $this->deleteJson('/api/v1/jadwal-shift/' . $this->idJadwalPadaTanggal($ctx['id_supir'], '2026-09-01'))
            ->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan' => $ctx['id_pengajuan'], 'nominal' => 200000,
            'periode_dari' => '2026-09-02', 'periode_sampai' => '2026-09-03',
        ]);
    }

    public function test_hapus_hari_terakhir_menghapus_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->batchSatuSupir(100000.0, '2026-09-01', '2026-09-01');

        $this->deleteJson('/api/v1/jadwal-shift/' . $this->idJadwalPadaTanggal($ctx['id_supir'], '2026-09-01'))
            ->assertStatus(200);

        $this->assertNotNull(DB::table('pengajuan_pengeluaran')
            ->where('id_pengajuan', $ctx['id_pengajuan'])->value('dihapus_pada'));
    }

    public function test_hapus_hari_saat_status_dicek_tidak_mengubah_nominal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->batchSatuSupir();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $ctx['id_pengajuan'])->update(['status' => 'dicek']);

        $this->deleteJson('/api/v1/jadwal-shift/' . $this->idJadwalPadaTanggal($ctx['id_supir'], '2026-09-01'))
            ->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan' => $ctx['id_pengajuan'], 'nominal' => 300000, 'status' => 'dicek',
        ]);
    }

    public function test_hapus_pengajuan_melepas_link_jadwal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $ctx = $this->batchSatuSupir();

        $this->deleteJson("/api/v1/arus-kas/pengajuan/{$ctx['id_pengajuan']}")->assertStatus(200);

        $this->assertSame(0, DB::table('jadwal_shift')->where('id_pengajuan', $ctx['id_pengajuan'])->count());
        $this->assertSame(3, DB::table('jadwal_shift')
            ->where('id_supir', $ctx['id_supir'])->whereNull('id_pengajuan')->whereNull('dihapus_pada')->count());
    }

    public function test_import_ditimpa_mewarisi_link_tanpa_pengajuan_dobel(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 100000.0);
        $supir = $this->makeSupir('Gina Import');
        $this->makePenugasan($proyek->id_proyek, $supir, $rute);
        $shiftPagi  = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-09-01', 'supir' => [$supir],
        ])->assertStatus(200);
        $idPengajuanAwal = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supir)->value('id_pengajuan');

        $idShiftMalam = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $idShiftMalam, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Malam', 'jam_mulai' => '20:00:00', 'jam_selesai' => '04:00:00',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $noSim = (string) DB::table('supir')->where('id_supir', $supir)->value('no_sim');

        $hasil = app(\App\Modules\JadwalShift\JadwalShiftService::class)->importMatriks(
            $this->bikinFileImport($noSim, 'Malam', '2026-09-01'),
            (string) $proyek->id_proyek,
            self::PERUSAHAAN_ID,
        );

        $this->assertSame([], $hasil['peringatan']);
        $this->assertCount(1, $hasil['ditimpa']);
        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_supir', $supir)->count());
        $this->assertSame(1, DB::table('jadwal_shift')
            ->where('id_pengajuan', $idPengajuanAwal)->whereNull('dihapus_pada')->count());
        $this->assertDatabaseHas('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuanAwal, 'nominal' => 100000]);
    }

    public function test_import_hari_baru_membuat_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 100000.0);
        $supir = $this->makeSupir('Hana Import Baru');
        $this->makePenugasan($proyek->id_proyek, $supir, $rute);
        $this->makeShift();
        $noSim = (string) DB::table('supir')->where('id_supir', $supir)->value('no_sim');

        $hasil = app(\App\Modules\JadwalShift\JadwalShiftService::class)->importMatriks(
            $this->bikinFileImport($noSim, 'Pagi', '2026-09-05'),
            (string) $proyek->id_proyek,
            self::PERUSAHAAN_ID,
        );

        $this->assertSame(1, $hasil['sukses']);
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_supir' => $supir, 'nominal' => 100000,
            'periode_dari' => '2026-09-05', 'periode_sampai' => '2026-09-05',
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

    public function test_detail_trip_menampilkan_pengajuan_periode(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $supir  = $this->makeSupir('Ika Card Trip');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir, $rute);
        $shift = $this->makeShift();

        $tanggalHariIni = now()->toDateString();
        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => $tanggalHariIni, 'supir' => [$supir],
        ])->assertStatus(200);
        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supir)->value('id_pengajuan');

        $resTrip = $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan]);
        $resTrip->assertStatus(201);
        $idTrip = (string) $resTrip->json('data.id_trip');

        $res = $this->getJson("/api/v1/trip/{$idTrip}");
        $res->assertStatus(200)
            ->assertJsonPath('data.pengajuan_uang_jalan.id_pengajuan', $idPengajuan)
            ->assertJsonPath('data.pengajuan_uang_jalan.periode.jumlah_hari', 1)
            ->assertJsonPath('data.pengajuan_uang_jalan.periode.tarif_per_hari', 150000);
    }
}
