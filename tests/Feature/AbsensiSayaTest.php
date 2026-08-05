<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AbsensiSayaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loginSebagaiKaryawan(): string
    {
        $user = $this->actingAsRole('SUPERADMIN');
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-ABSSAYA-' . Str::random(4), 'nama_karyawan' => 'Staff Absen', 'aktif' => 1,
            'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $user->id_pengguna)->update(['id_karyawan' => $idKaryawan]);
        Sanctum::actingAs($user->fresh(), ['*']);
        return $idKaryawan;
    }

    private function setPengaturan(): void
    {
        $this->putJson('/api/v1/absensi/pengaturan', [
            'jam_masuk' => '08:00', 'jam_pulang' => '17:00', 'toleransi_terlambat_menit' => 15,
        ])->assertStatus(200);
    }

    public function test_absen_masuk_mencatat_jam_lokasi_dan_status_hadir(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $res = $this->postJson('/api/v1/absensi/saya/masuk', [
            'latitude' => -6.2000001, 'longitude' => 106.8000001, 'alamat' => 'Jl. Kantor No. 1, Bekasi',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'hadir')
            ->assertJsonPath('data.alamat_masuk', 'Jl. Kantor No. 1, Bekasi');

        $baris = DB::table('absensi')->where('id_karyawan', $idKaryawan)->where('tanggal', '2026-08-06')->first();
        $this->assertNotNull($baris);
        $this->assertSame('07:55:00', $baris->jam_masuk);
        $this->assertEquals(-6.2000001, (float) $baris->latitude_masuk);
    }

    public function test_absen_masuk_lewat_toleransi_berstatus_terlambat(): void
    {
        $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 08:20:00'));

        $this->postJson('/api/v1/absensi/saya/masuk', [])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'terlambat');
    }

    public function test_absen_masuk_dua_kali_ditolak(): void
    {
        $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(422);
    }

    public function test_absen_pulang_butuh_masuk_dulu_dan_sekali_saja(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $this->postJson('/api/v1/absensi/saya/pulang', [])->assertStatus(422);

        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        Carbon::setTestNow(Carbon::parse('2026-08-06 17:05:00'));
        $this->postJson('/api/v1/absensi/saya/pulang', ['alamat' => 'Jl. Pulang'])
            ->assertStatus(200)
            ->assertJsonPath('data.alamat_pulang', 'Jl. Pulang');

        $baris = DB::table('absensi')->where('id_karyawan', $idKaryawan)->first();
        $this->assertSame('17:05:00', $baris->jam_pulang);

        $this->postJson('/api/v1/absensi/saya/pulang', [])->assertStatus(422);
    }

    public function test_hari_ini_mengembalikan_absensi_atau_null(): void
    {
        $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $this->getJson('/api/v1/absensi/saya/hari-ini')->assertStatus(200)->assertJsonPath('data', null);

        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        $this->getJson('/api/v1/absensi/saya/hari-ini')->assertStatus(200)->assertJsonPath('data.status', 'hadir');
    }

    public function test_akun_tanpa_tautan_karyawan_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(422);
    }

    public function test_absen_masuk_ditolak_saat_sedang_cuti_disetujui(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $idJenis = (string) Str::uuid();
        DB::table('jenis_cuti')->insert([
            'id_jenis_cuti' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_jenis' => 'Cuti Absen Sendiri', 'mengurangi_saldo' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengajuan_cuti')->insert([
            'id_pengajuan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'id_jenis_cuti' => $idJenis,
            'tanggal_mulai' => '2026-08-06', 'tanggal_selesai' => '2026-08-06',
            'jumlah_hari' => 1, 'status' => 'disetujui', 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(422);
    }

    public function test_absen_masuk_ditolak_saat_sudah_dicatat_admin(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        DB::table('absensi')->insert([
            'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'tanggal' => '2026-08-06',
            'status' => 'sakit', 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(422);
    }
}
