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

class CutiTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $nama = 'Karyawan Cuti', string $nik = 'NIK-CUTI-01'): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSupir(string $nama = 'Supir Cuti'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisCuti(string $nama = 'Cuti Tahunan Tes', bool $mengurangiSaldo = true): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_cuti')->insert([
            'id_jenis_cuti' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_jenis' => $nama, 'mengurangi_saldo' => $mengurangiSaldo ? 1 : 0,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatPengajuan(string $idJenis, ?string $idKaryawan = null, ?string $idSupir = null, string $mulai = '2026-08-03', string $selesai = '2026-08-05'): string
    {
        $res = $this->postJson('/api/v1/pengajuan-cuti', [
            'id_karyawan'     => $idKaryawan,
            'id_supir'        => $idSupir,
            'id_jenis_cuti'   => $idJenis,
            'tanggal_mulai'   => $mulai,
            'tanggal_selesai' => $selesai,
            'alasan'          => 'Keperluan keluarga',
        ]);
        $res->assertStatus(201);
        return $res->json('data.id_pengajuan');
    }

    public function test_membuat_pengajuan_menghitung_jumlah_hari(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisCuti();
        $idKaryawan = $this->makeKaryawan();

        $res = $this->postJson('/api/v1/pengajuan-cuti', [
            'id_karyawan'     => $idKaryawan,
            'id_jenis_cuti'   => $idJenis,
            'tanggal_mulai'   => '2026-08-03',
            'tanggal_selesai' => '2026-08-07',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jumlah_hari', 5)
            ->assertJsonPath('data.status', 'menunggu')
            ->assertJsonPath('data.tipe_orang', 'karyawan');
    }

    public function test_pengajuan_tumpang_tindih_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisCuti();
        $idKaryawan = $this->makeKaryawan();
        $this->buatPengajuan($idJenis, $idKaryawan);

        $res = $this->postJson('/api/v1/pengajuan-cuti', [
            'id_karyawan'     => $idKaryawan,
            'id_jenis_cuti'   => $idJenis,
            'tanggal_mulai'   => '2026-08-05',
            'tanggal_selesai' => '2026-08-08',
        ]);

        $res->assertStatus(422);
    }

    public function test_setujui_memotong_saldo_dan_saldo_tercermin(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisCuti();
        $idKaryawan = $this->makeKaryawan();
        $idPengajuan = $this->buatPengajuan($idJenis, $idKaryawan); // 3 hari

        $this->postJson("/api/v1/pengajuan-cuti/{$idPengajuan}/setujui")->assertStatus(200);

        $res = $this->getJson("/api/v1/saldo-cuti?id_karyawan={$idKaryawan}&tahun=2026");
        $res->assertStatus(200)
            ->assertJsonPath('data.jatah', 12)
            ->assertJsonPath('data.terpakai', 3)
            ->assertJsonPath('data.sisa', 9);
    }

    public function test_setujui_ditolak_bila_saldo_kurang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisCuti();
        $idKaryawan = $this->makeKaryawan();
        // 20 hari > jatah 12
        $idPengajuan = $this->buatPengajuan($idJenis, $idKaryawan, null, '2026-08-03', '2026-08-22');

        $this->postJson("/api/v1/pengajuan-cuti/{$idPengajuan}/setujui")->assertStatus(422);
    }

    public function test_jenis_tanpa_potong_saldo_tidak_mendebit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisCuti('Sakit Tes', false);
        $idKaryawan = $this->makeKaryawan();
        $idPengajuan = $this->buatPengajuan($idJenis, $idKaryawan);

        $this->postJson("/api/v1/pengajuan-cuti/{$idPengajuan}/setujui")->assertStatus(200);

        $res = $this->getJson("/api/v1/saldo-cuti?id_karyawan={$idKaryawan}&tahun=2026");
        $res->assertStatus(200)->assertJsonPath('data.terpakai', 0)->assertJsonPath('data.sisa', 12);
    }

    public function test_batalkan_cuti_disetujui_mengembalikan_saldo(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisCuti();
        $idKaryawan = $this->makeKaryawan();
        $idPengajuan = $this->buatPengajuan($idJenis, $idKaryawan);

        $this->postJson("/api/v1/pengajuan-cuti/{$idPengajuan}/setujui")->assertStatus(200);
        $this->postJson("/api/v1/pengajuan-cuti/{$idPengajuan}/batalkan")->assertStatus(200);

        $res = $this->getJson("/api/v1/saldo-cuti?id_karyawan={$idKaryawan}&tahun=2026");
        $res->assertStatus(200)->assertJsonPath('data.terpakai', 0)->assertJsonPath('data.sisa', 12);
    }

    public function test_tolak_dengan_catatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisCuti();
        $idKaryawan = $this->makeKaryawan();
        $idPengajuan = $this->buatPengajuan($idJenis, $idKaryawan);

        $res = $this->postJson("/api/v1/pengajuan-cuti/{$idPengajuan}/tolak", ['catatan' => 'Operasional padat']);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.catatan_proses', 'Operasional padat');
    }

    public function test_penyesuaian_saldo_menambah_sisa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan();

        $this->postJson('/api/v1/saldo-cuti/penyesuaian', [
            'id_karyawan' => $idKaryawan,
            'tahun'       => 2026,
            'jumlah_hari' => 3,
            'keterangan'  => 'Carry forward 2025',
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/saldo-cuti?id_karyawan={$idKaryawan}&tahun=2026");
        $res->assertStatus(200)->assertJsonPath('data.sisa', 15);
    }

    public function test_rekap_saldo_memuat_karyawan_dan_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKaryawan('Andi Rekap', 'NIK-REKAP-01');
        $this->makeSupir('Supir Rekap');

        $res = $this->getJson('/api/v1/saldo-cuti/rekap?tahun=2026');
        $res->assertStatus(200);
        $data = $res->json('data');
        $tipe = array_column($data, 'tipe');
        $this->assertContains('karyawan', $tipe);
        $this->assertContains('supir', $tipe);
    }

    public function test_supir_cuti_tidak_bisa_mulai_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisCuti();
        $idSupir = $this->makeSupir('Supir Blokir');

        $hariIni = now()->toDateString();
        $idPengajuan = $this->buatPengajuan($idJenis, null, $idSupir, $hariIni, now()->addDays(2)->toDateString());
        $this->postJson("/api/v1/pengajuan-cuti/{$idPengajuan}/setujui")->assertStatus(200);

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Blokir Cuti',
        ]);
        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $idSupir,
        ]);
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
            'rute'            => 'Jakarta - Bandung',
        ]);
        $trip = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'belum_mulai']);

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin");
        $res->assertStatus(422);
        $this->assertStringContainsString('cuti', strtolower($res->json('message')));
    }

    public function test_jenis_cuti_crud(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $resCreate = $this->postJson('/api/v1/jenis-cuti', [
            'nama_jenis'       => 'Cuti Melahirkan',
            'mengurangi_saldo' => false,
        ]);
        $resCreate->assertStatus(201)->assertJsonPath('data.mengurangi_saldo', false);
        $id = $resCreate->json('data.id_jenis_cuti');

        $this->putJson("/api/v1/jenis-cuti/{$id}", ['nama_jenis' => 'Cuti Melahirkan 3 Bulan'])
            ->assertStatus(200)
            ->assertJsonPath('data.nama_jenis', 'Cuti Melahirkan 3 Bulan');

        $this->deleteJson("/api/v1/jenis-cuti/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('jenis_cuti', ['id_jenis_cuti' => $id]);
    }
}
