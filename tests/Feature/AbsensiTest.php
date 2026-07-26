<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AbsensiTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $nama = 'Karyawan Absen', string $nik = 'NIK-ABS-01', int $aktif = 1, float $gaji = 0): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => $nama, 'aktif' => $aktif,
            'gaji_pokok' => $gaji, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeCutiDisetujui(string $idKaryawan, string $mulai, string $selesai): void
    {
        $idJenis = (string) Str::uuid();
        DB::table('jenis_cuti')->insert([
            'id_jenis_cuti' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_jenis' => 'Cuti Absen Tes', 'mengurangi_saldo' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengajuan_cuti')->insert([
            'id_pengajuan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'id_jenis_cuti' => $idJenis,
            'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai,
            'jumlah_hari' => 1, 'status' => 'disetujui', 'dibuat_pada' => now(),
        ]);
    }

    public function test_harian_memuat_karyawan_aktif_dengan_flag_cuti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idBiasa = $this->makeKaryawan('Andi Hadir', 'NIK-ABS-A');
        $idCuti  = $this->makeKaryawan('Budi Cuti', 'NIK-ABS-B');
        $this->makeKaryawan('Orang Nonaktif', 'NIK-ABS-C', 0);
        $this->makeCutiDisetujui($idCuti, now()->toDateString(), now()->toDateString());

        $res = $this->getJson('/api/v1/absensi/harian?tanggal=' . now()->toDateString());

        $res->assertStatus(200);
        $data = collect($res->json('data'));
        $this->assertCount(2, $data);
        $this->assertFalse($data->firstWhere('id_karyawan', $idBiasa)['sedang_cuti']);
        $this->assertTrue($data->firstWhere('id_karyawan', $idCuti)['sedang_cuti']);
    }

    public function test_simpan_harian_upsert_dan_lewati_yang_cuti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idBiasa = $this->makeKaryawan('Andi Simpan', 'NIK-ABS-D');
        $idCuti  = $this->makeKaryawan('Budi Dilewati', 'NIK-ABS-E');
        $tanggal = now()->toDateString();
        $this->makeCutiDisetujui($idCuti, $tanggal, $tanggal);

        $res = $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => $tanggal,
            'entries' => [
                ['id_karyawan' => $idBiasa, 'status' => 'hadir', 'jam_masuk' => '08:00'],
                ['id_karyawan' => $idCuti, 'status' => 'hadir'],
            ],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.tersimpan', 1)
            ->assertJsonPath('data.dilewati', 1);

        $this->assertDatabaseHas('absensi', ['id_karyawan' => $idBiasa, 'status' => 'hadir']);
        $this->assertDatabaseMissing('absensi', ['id_karyawan' => $idCuti]);

        // Upsert: simpan ulang dengan status berbeda tidak menduplikasi baris
        $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => $tanggal,
            'entries' => [['id_karyawan' => $idBiasa, 'status' => 'terlambat']],
        ])->assertStatus(200);

        $rows = DB::table('absensi')->where('id_karyawan', $idBiasa)->whereNull('dihapus_pada')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('terlambat', $rows[0]->status);
    }

    public function test_simpan_harian_status_null_menghapus_catatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Andi Hapus', 'NIK-ABS-F');
        $tanggal = now()->toDateString();

        $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => $tanggal,
            'entries' => [['id_karyawan' => $idKaryawan, 'status' => 'hadir']],
        ])->assertStatus(200);

        $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => $tanggal,
            'entries' => [['id_karyawan' => $idKaryawan, 'status' => null]],
        ])->assertStatus(200);

        $this->assertSoftDeleted('absensi', ['id_karyawan' => $idKaryawan]);
    }

    public function test_status_tidak_valid_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Andi Invalid', 'NIK-ABS-G');

        $res = $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => now()->toDateString(),
            'entries' => [['id_karyawan' => $idKaryawan, 'status' => 'bolos']],
        ]);

        $res->assertStatus(422);
    }

    public function test_pengaturan_default_dan_simpan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $resDefault = $this->getJson('/api/v1/absensi/pengaturan');
        $resDefault->assertStatus(200)
            ->assertJsonPath('data.jam_masuk', '08:00')
            ->assertJsonPath('data.jam_pulang', '17:00')
            ->assertJsonPath('data.toleransi_terlambat_menit', 15);

        $resSimpan = $this->putJson('/api/v1/absensi/pengaturan', [
            'jam_masuk'  => '08:30',
            'jam_pulang' => '16:30',
            'toleransi_terlambat_menit' => 10,
        ]);
        $resSimpan->assertStatus(200)->assertJsonPath('data.jam_masuk', '08:30');

        $this->getJson('/api/v1/absensi/pengaturan')
            ->assertJsonPath('data.jam_pulang', '16:30')
            ->assertJsonPath('data.toleransi_terlambat_menit', 10);
    }

    public function test_pengaturan_jam_pulang_harus_setelah_jam_masuk(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->putJson('/api/v1/absensi/pengaturan', [
            'jam_masuk'  => '17:00',
            'jam_pulang' => '08:00',
            'toleransi_terlambat_menit' => 0,
        ])->assertStatus(422);
    }

    public function test_rekap_menghitung_menit_dan_upah_lembur(): void
    {
        $this->actingAsRole('SUPERADMIN');
        // gaji 1.730.000 -> upah sejam formula 1/173 = Rp 10.000
        $idKaryawan = $this->makeKaryawan('Andi Lembur', 'NIK-ABS-L', 1, 1730000);
        $bulan = now()->format('Y-m');

        // jam pulang standar default 17:00 — dua hari lembur: 90 menit + 30 menit
        DB::table('absensi')->insert([
            [
                'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_karyawan' => $idKaryawan, 'tanggal' => $bulan . '-05', 'status' => 'hadir',
                'jam_masuk' => '08:00', 'jam_pulang' => '18:30', 'dibuat_pada' => now(),
            ],
            [
                'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_karyawan' => $idKaryawan, 'tanggal' => $bulan . '-06', 'status' => 'hadir',
                'jam_masuk' => '08:00', 'jam_pulang' => '17:30', 'dibuat_pada' => now(),
            ],
            [
                'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_karyawan' => $idKaryawan, 'tanggal' => $bulan . '-07', 'status' => 'hadir',
                'jam_masuk' => '08:00', 'jam_pulang' => '16:00', 'dibuat_pada' => now(),
            ],
        ]);

        $res = $this->getJson('/api/v1/absensi/rekap?bulan=' . $bulan);

        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_karyawan', $idKaryawan);
        $this->assertSame(120, $row['lembur_menit']);
        // hari 1 (90m): 1,5×10rb×1jam + 2×10rb×0,5jam = 25.000; hari 2 (30m): 1,5×10rb×0,5jam = 7.500
        $this->assertSame(32500, $row['lembur_rupiah']);
    }

    public function test_rekap_bulanan_menghitung_status_dan_cuti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Andi Rekap', 'NIK-ABS-H');
        $bulan = now()->format('Y-m');

        DB::table('absensi')->insert([
            [
                'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_karyawan' => $idKaryawan, 'tanggal' => $bulan . '-01', 'status' => 'hadir', 'dibuat_pada' => now(),
            ],
            [
                'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_karyawan' => $idKaryawan, 'tanggal' => $bulan . '-02', 'status' => 'hadir', 'dibuat_pada' => now(),
            ],
            [
                'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_karyawan' => $idKaryawan, 'tanggal' => $bulan . '-03', 'status' => 'sakit', 'dibuat_pada' => now(),
            ],
        ]);
        $this->makeCutiDisetujui($idKaryawan, $bulan . '-10', $bulan . '-12');

        $res = $this->getJson('/api/v1/absensi/rekap?bulan=' . $bulan);

        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_karyawan', $idKaryawan);
        $this->assertSame(2, $row['hadir']);
        $this->assertSame(1, $row['sakit']);
        $this->assertSame(0, $row['alpha']);
        $this->assertSame(3, $row['cuti']);
    }
}
