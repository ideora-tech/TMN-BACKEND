<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripJadwalSayaTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'aaaa3333-0000-4000-8000-000000000001';

    private function buatPenggunaSupir(): Pengguna
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPIR',
            'username'      => 'supir_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        DB::table('menu')->insertOrIgnore([
            'id_menu'     => self::ID_MENU_TRIP,
            'nama_menu'   => 'Trip Monitor',
            'path'        => '/trip',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);
        foreach (['lihat', 'tambah'] as $aksi) {
            DB::table('izin_peran')->insertOrIgnore([
                'id_izin'     => (string) Str::uuid(),
                'kode_peran'  => 'SUPIR',
                'id_menu'     => self::ID_MENU_TRIP,
                'aksi'        => $aksi,
                'diizinkan'   => 1,
                'dibuat_pada' => now(),
            ]);
        }

        return $pengguna;
    }

    private function actingAsSupir(): object
    {
        $pengguna = $this->buatPenggunaSupir();

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $idSupir,
            'id_pengguna'   => $pengguna->id_pengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Test',
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);

        Sanctum::actingAs($pengguna, ['*']);

        return (object) ['pengguna' => $pengguna, 'id_supir' => $idSupir];
    }

    private function actingAsPenggunaTanpaSupir(): void
    {
        $pengguna = $this->buatPenggunaSupir();
        Sanctum::actingAs($pengguna, ['*']);
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Trip Jadwal Saya Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Trip Jadwal Saya Test',
        ]);
    }

    private function makePenugasanTanggal(string $idSupir, string $idProyek, string $tanggal, string $status = 'aktif'): PenugasanModel
    {
        return PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $idProyek,
            'id_supir'      => $idSupir,
            'status'        => $status,
            'tanggal_tugas' => $tanggal,
        ]);
    }

    private function makeTrip(string $idPenugasan, string $status, ?string $waktuCheckin = null, ?string $waktuCheckout = null): string
    {
        $idJadwal = (string) Str::uuid();
        DB::table('jadwal_keberangkatan')->insert([
            'id_jadwal'       => $idJadwal,
            'id_penugasan'    => $idPenugasan,
            'waktu_berangkat' => now(),
            'dibuat_pada'     => now(),
        ]);

        $idTrip = (string) Str::uuid();
        DB::table('trip')->insert([
            'id_trip'        => $idTrip,
            'id_jadwal'      => $idJadwal,
            'status'         => $status,
            'waktu_checkin'  => $waktuCheckin,
            'waktu_checkout' => $waktuCheckout,
            'dibuat_pada'    => now(),
        ]);

        return $idTrip;
    }

    private function makeShift(string $nama = 'Pagi', string $jamMulai = '08:00:00', string $jamSelesai = '20:00:00'): string
    {
        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'      => $idShift,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'jam_mulai'     => $jamMulai,
            'jam_selesai'   => $jamSelesai,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $idShift;
    }

    private function makeJadwalShift(string $idProyek, string $idShift, string $idSupir, string $tanggal): void
    {
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(),
            'id_proyek'       => $idProyek,
            'id_shift'        => $idShift,
            'id_supir'        => $idSupir,
            'tanggal'         => $tanggal,
            'dibuat_pada'     => now(),
        ]);
    }

    /**
     * Shift tanpa penugasan harian di tanggal yang sama dilewati (bukan
     * dikirim info-only ber-id_penugasan null) — lihat docblock
     * TripService::jadwalUntukSupir untuk alasan (mobile crash bila null).
     */
    public function test_jadwal_saya_shift_tanpa_penugasan_tanggal_sama_dilewati(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $idShift = $this->makeShift();
        $this->makeJadwalShift($proyek->id_proyek, $idShift, $ctx->id_supir, '2026-07-31');

        $res = $this->getJson('/api/trip/jadwal-saya?dari=2026-07-27&sampai=2026-08-02');

        $res->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_jadwal_saya_penugasan_tanggal_lain_tidak_menempel_ke_shift(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-07-28');
        $idShift = $this->makeShift();
        $this->makeJadwalShift($proyek->id_proyek, $idShift, $ctx->id_supir, '2026-07-31');

        $res = $this->getJson('/api/trip/jadwal-saya?dari=2026-07-27&sampai=2026-08-02');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_penugasan', $penugasan->id_penugasan)
            ->assertJsonPath('data.0.tanggal_tugas', '2026-07-28')
            ->assertJsonPath('data.0.shift', null);
    }

    public function test_jadwal_saya_gabungkan_shift_dan_penugasan_tanpa_duplikat(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-07-31');
        $idShift = $this->makeShift();
        $this->makeJadwalShift($proyek->id_proyek, $idShift, $ctx->id_supir, '2026-07-31');

        $res = $this->getJson('/api/trip/jadwal-saya?dari=2026-07-27&sampai=2026-08-02');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_penugasan', $penugasan->id_penugasan)
            ->assertJsonPath('data.0.tanggal_tugas', '2026-07-31')
            ->assertJsonPath('data.0.shift.nama', 'Pagi');
    }

    public function test_jadwal_saya_shift_supir_lain_tidak_ikut(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $this->makePenugasanTanggal($pemilik->id_supir, $proyek->id_proyek, '2026-07-20');
        $idShift = $this->makeShift();
        $this->makeJadwalShift($proyek->id_proyek, $idShift, $pemilik->id_supir, '2026-07-31');

        $this->actingAsSupir();

        $this->getJson('/api/trip/jadwal-saya?dari=2026-07-27&sampai=2026-08-02')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_jadwal_saya_tampilkan_penugasan_dalam_rentang_urut_tanggal(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $b = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-05');
        $a = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-03');
        $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-15');

        $res = $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-09');

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id_penugasan', $a->id_penugasan)
            ->assertJsonPath('data.0.tanggal_tugas', '2026-08-03')
            ->assertJsonPath('data.0.status', 'aktif')
            ->assertJsonPath('data.0.proyek.nama_proyek', 'Proyek Trip Jadwal Saya Test')
            ->assertJsonPath('data.0.proyek.kode_proyek', $proyek->kode_proyek)
            ->assertJsonPath('data.0.proyek.nama_klien', 'Klien Trip Jadwal Saya Test')
            ->assertJsonPath('data.0.trip_berjalan', null)
            ->assertJsonPath('data.1.id_penugasan', $b->id_penugasan);
    }

    public function test_jadwal_saya_tidak_tampilkan_penugasan_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $lain = $this->actingAsSupir();
        $this->makePenugasanTanggal($lain->id_supir, $proyek->id_proyek, '2026-08-03');

        $this->actingAsSupir();

        $this->getJson('/api/trip/jadwal-saya?dari=2026-08-01&sampai=2026-08-07')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_jadwal_saya_menyertakan_trip_berjalan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $p = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-03');
        $idTrip = $this->makeTrip($p->id_penugasan, 'berjalan', '2026-08-03 08:15:00');

        $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-03')
            ->assertStatus(200)
            ->assertJsonPath('data.0.trip_berjalan.id_trip', $idTrip)
            ->assertJsonPath('data.0.trip_berjalan.status', 'berjalan')
            ->assertJsonPath('data.0.trip_berjalan.punya_laporan', false);

        DB::table('laporan_perjalanan')->insert([
            'id_laporan'    => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip'       => $idTrip,
            'dibuat_pada'   => now(),
        ]);

        $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-03')
            ->assertStatus(200)
            ->assertJsonPath('data.0.trip_berjalan.punya_laporan', true);
    }

    public function test_trip_berjalan_hanya_menempel_di_tanggal_checkin(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-03');
        $p2 = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-04');
        $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-05');
        $idTrip = $this->makeTrip($p2->id_penugasan, 'berjalan', '2026-08-04 09:00:00');

        $res = $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-05');

        $res->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.tanggal_tugas', '2026-08-03')
            ->assertJsonPath('data.0.trip_berjalan', null)
            ->assertJsonPath('data.1.tanggal_tugas', '2026-08-04')
            ->assertJsonPath('data.1.trip_berjalan.id_trip', $idTrip)
            ->assertJsonPath('data.2.tanggal_tugas', '2026-08-05')
            ->assertJsonPath('data.2.trip_berjalan', null);
    }

    public function test_jumlah_trip_selesai_dihitung_per_tanggal(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $p = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-03');
        $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-04');
        $this->makeTrip($p->id_penugasan, 'selesai', '2026-08-03 08:30:00', '2026-08-03 17:00:00');
        $this->makeTrip($p->id_penugasan, 'selesai', '2026-08-03 18:00:00', '2026-08-03 20:00:00');

        $res = $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-04');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.tanggal_tugas', '2026-08-03')
            ->assertJsonPath('data.0.jumlah_trip_selesai', 2)
            ->assertJsonPath('data.1.tanggal_tugas', '2026-08-04')
            ->assertJsonPath('data.1.jumlah_trip_selesai', 0);
    }

    public function test_jumlah_laporan_terisi_dihitung_per_tanggal(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $p = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-03');
        $t1 = $this->makeTrip($p->id_penugasan, 'selesai', '2026-08-03 08:30:00', '2026-08-03 17:00:00');
        $this->makeTrip($p->id_penugasan, 'selesai', '2026-08-03 18:00:00', '2026-08-03 20:00:00');
        DB::table('laporan_perjalanan')->insert([
            'id_laporan'    => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip'       => $t1,
            'biaya_bbm'     => 100000,
            'uang_jalan'    => 0,
            'dibuat_pada'   => now(),
        ]);

        $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-03')
            ->assertStatus(200)
            ->assertJsonPath('data.0.jumlah_trip_selesai', 2)
            ->assertJsonPath('data.0.jumlah_laporan_terisi', 1);
    }

    public function test_trip_berjalan_tanpa_jadwal_di_tanggalnya_tetap_muncul(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $p = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-05');
        $idTrip = $this->makeTrip($p->id_penugasan, 'berjalan', '2026-08-03 07:45:00');

        $res = $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-09');

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.tanggal_tugas', '2026-08-03')
            ->assertJsonPath('data.0.trip_berjalan.id_trip', $idTrip)
            ->assertJsonPath('data.0.proyek.nama_proyek', 'Proyek Trip Jadwal Saya Test')
            ->assertJsonPath('data.1.tanggal_tugas', '2026-08-05')
            ->assertJsonPath('data.1.trip_berjalan', null);
    }

    public function test_jadwal_saya_menampilkan_armada_pinjaman_dari_alokasi(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-03');

        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $idArmada,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 777 PJ',
            'merk'          => 'Hino',
            'dibuat_pada'   => now(),
        ]);
        DB::table('alokasi_armada')->insert([
            'id_alokasi'  => (string) Str::uuid(),
            'tanggal'     => '2026-08-03',
            'id_proyek'   => $proyek->id_proyek,
            'id_supir'    => $ctx->id_supir,
            'id_armada'   => $idArmada,
            'sumber'      => 'otomatis',
            'dibuat_pada' => now(),
        ]);

        $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-03')
            ->assertStatus(200)
            ->assertJsonPath('data.0.armada.nopol', 'B 777 PJ');
    }

    public function test_jadwal_saya_memuat_penugasan_harian(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();

        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $idArmada,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 999 HR',
            'merk'          => 'Hino',
            'dibuat_pada'   => now(),
        ]);

        $penugasan = PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $ctx->id_supir,
            'id_armada'     => $idArmada,
            'status'        => 'aktif',
            'tanggal_tugas' => '2026-08-05',
        ]);

        $res = $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-09');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_penugasan', $penugasan->id_penugasan)
            ->assertJsonPath('data.0.tanggal_tugas', '2026-08-05')
            ->assertJsonPath('data.0.armada.nopol', 'B 999 HR');
    }

    public function test_jadwal_saya_validasi_parameter(): void
    {
        $this->actingAsSupir();

        $this->getJson('/api/trip/jadwal-saya')->assertStatus(422);
        $this->getJson('/api/trip/jadwal-saya?dari=03-08-2026&sampai=2026-08-09')->assertStatus(422);
        $this->getJson('/api/trip/jadwal-saya?dari=2026-08-09&sampai=2026-08-03')->assertStatus(422);
        $this->getJson('/api/trip/jadwal-saya?dari=2026-01-01&sampai=2026-12-31')->assertStatus(422);
    }

    public function test_jadwal_saya_pengguna_tanpa_data_supir_404(): void
    {
        $this->actingAsPenggunaTanpaSupir();

        $this->getJson('/api/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-09')
            ->assertStatus(404);
    }
}
