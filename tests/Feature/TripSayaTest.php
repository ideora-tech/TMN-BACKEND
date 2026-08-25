<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\ProyekRute\ProyekRuteModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripSayaTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'aaaa3333-0000-4000-8000-000000000001';

    private function actingAsSupir(): object
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

        Sanctum::actingAs($pengguna, ['*']);

        return (object) ['pengguna' => $pengguna, 'id_supir' => $idSupir];
    }

    private function absenHadir(string $idSupir, string $status = 'hadir'): void
    {
        DB::table('absensi_supir')->insert([
            'id_absensi'    => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_supir'      => $idSupir,
            'tanggal'       => now()->toDateString(),
            'status'        => $status,
            'dibuat_pada'   => now(),
        ]);
    }

    private function buatLaporanKosong(string $idTrip): void
    {
        DB::table('laporan_perjalanan')->insert([
            'id_laporan'    => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip'       => $idTrip,
            'dibuat_pada'   => now(),
        ]);
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Trip Saya Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Trip Saya Test',
        ]);
    }

    private function makePenugasan(string $idSupir, string $idProyek, string $status = 'aktif'): PenugasanModel
    {
        return PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $idProyek,
            'id_supir'      => $idSupir,
            'status'        => $status,
            'tanggal_tugas' => now()->toDateString(),
        ]);
    }

    public function test_supir_bisa_lihat_detail_penugasan_miliknya(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $response = $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}");

        $response->assertStatus(200)
            ->assertJsonPath('data.penugasan.id_penugasan', $penugasan->id_penugasan)
            ->assertJsonPath('data.trip', null)
            ->assertJsonPath('data.rute_tersedia', []);
    }

    public function test_detail_penugasan_menyertakan_klien_shift_dan_armada_hari_ini(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $hariIni = now()->toDateString();
        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'      => $idShift,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'PAGI',
            'jam_mulai'     => '08:00:00',
            'jam_selesai'   => '20:00:00',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(),
            'id_proyek'       => $proyek->id_proyek,
            'id_shift'        => $idShift,
            'id_supir'        => $ctx->id_supir,
            'tanggal'         => $hariIni,
            'dibuat_pada'     => now(),
        ]);

        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $idArmada,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 555 PJM',
            'merk'          => 'Hino',
            'dibuat_pada'   => now(),
        ]);
        DB::table('alokasi_armada')->insert([
            'id_alokasi'  => (string) Str::uuid(),
            'tanggal'     => $hariIni,
            'id_proyek'   => $proyek->id_proyek,
            'id_supir'    => $ctx->id_supir,
            'id_armada'   => $idArmada,
            'sumber'      => 'otomatis',
            'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}")
            ->assertStatus(200)
            ->assertJsonPath('data.nama_klien', 'Klien Trip Saya Test')
            ->assertJsonPath('data.shift_hari_ini.nama', 'PAGI')
            ->assertJsonPath('data.shift_hari_ini.jam_mulai', '08:00:00')
            ->assertJsonPath('data.shift_hari_ini.jam_selesai', '20:00:00')
            ->assertJsonPath('data.armada_hari_ini.nopol', 'B 555 PJM');
    }

    public function test_detail_penugasan_tanpa_shift_hari_ini_bernilai_null(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}")
            ->assertStatus(200)
            ->assertJsonPath('data.shift_hari_ini', null)
            ->assertJsonPath('data.armada_hari_ini', null)
            ->assertJsonPath('data.jumlah_trip_selesai_hari_ini', 0);
    }

    public function test_detail_penugasan_dengan_parameter_tanggal_pakai_konteks_tanggal_itu(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'      => $idShift,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'MALAM',
            'jam_mulai'     => '20:00:00',
            'jam_selesai'   => '08:00:00',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(),
            'id_proyek'       => $proyek->id_proyek,
            'id_shift'        => $idShift,
            'id_supir'        => $ctx->id_supir,
            'tanggal'         => '2026-09-10',
            'dibuat_pada'     => now(),
        ]);

        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $idArmada,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 910 SEP',
            'merk'          => 'Hino',
            'dibuat_pada'   => now(),
        ]);
        DB::table('alokasi_armada')->insert([
            'id_alokasi'  => (string) Str::uuid(),
            'tanggal'     => '2026-09-10',
            'id_proyek'   => $proyek->id_proyek,
            'id_supir'    => $ctx->id_supir,
            'id_armada'   => $idArmada,
            'sumber'      => 'otomatis',
            'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}?tanggal=2026-09-10")
            ->assertStatus(200)
            ->assertJsonPath('data.shift_hari_ini.nama', 'MALAM')
            ->assertJsonPath('data.armada_hari_ini.nopol', 'B 910 SEP')
            ->assertJsonPath('data.jumlah_trip_selesai_hari_ini', 0);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}")
            ->assertStatus(200)
            ->assertJsonPath('data.shift_hari_ini', null)
            ->assertJsonPath('data.armada_hari_ini', null);
    }

    public function test_detail_penugasan_parameter_tanggal_tidak_valid_ditolak(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}?tanggal=10-09-2026")
            ->assertStatus(422);
    }

    public function test_detail_penugasan_menghitung_trip_selesai_hari_ini(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $this->absenHadir($ctx->id_supir);

        $mulai = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $this->buatLaporanKosong($mulai->json('data.id_trip'));
        $this->postJson("/api/trip/{$mulai->json('data.id_trip')}/checkout-saya")->assertStatus(200);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}")
            ->assertStatus(200)
            ->assertJsonPath('data.trip', null)
            ->assertJsonPath('data.jumlah_trip_selesai_hari_ini', 1);
    }

    public function test_supir_tidak_bisa_lihat_penugasan_milik_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $penugasan = $this->makePenugasan($pemilik->id_supir, $proyek->id_proyek);

        $this->actingAsSupir();

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}")
            ->assertStatus(403);
    }

    public function test_riwayat_saya_hanya_tampilkan_trip_selesai_milik_supir_sendiri(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek, 'selesai');

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);
        TripModel::create([
            'id_jadwal'      => $jadwal->id_jadwal,
            'status'         => 'selesai',
            'waktu_checkin'  => now()->subHour(),
            'waktu_checkout' => now(),
        ]);

        $response = $this->getJson('/api/trip/riwayat-saya');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'selesai');
    }

    public function test_riwayat_saya_filter_status_selesai_dengan_total_akumulasi(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek, 'selesai');

        foreach ([['selesai', '2026-08-01'], ['selesai', '2026-08-04'], ['dibatalkan', '2026-08-05']] as [$status, $tanggal]) {
            $jadwal = JadwalKeberangkatanModel::create([
                'id_penugasan'    => $penugasan->id_penugasan,
                'waktu_berangkat' => "{$tanggal} 08:00:00",
            ]);
            TripModel::create([
                'id_jadwal'      => $jadwal->id_jadwal,
                'status'         => $status,
                'waktu_checkin'  => "{$tanggal} 08:00:00",
                'waktu_checkout' => $status === 'selesai' ? "{$tanggal} 17:00:00" : null,
            ]);
        }

        $this->getJson('/api/trip/riwayat-saya?status=selesai&limit=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.status', 'selesai');

        $this->getJson('/api/trip/riwayat-saya?status=ngawur')
            ->assertStatus(422);
    }

    public function test_riwayat_saya_bisa_difilter_per_tanggal(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek, 'selesai');

        $jadwalLama = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-01 08:00:00',
        ]);
        $tripLama = TripModel::create([
            'id_jadwal'      => $jadwalLama->id_jadwal,
            'status'         => 'selesai',
            'waktu_checkin'  => '2026-08-01 08:00:00',
            'waktu_checkout' => '2026-08-01 17:00:00',
        ]);

        $jadwalBaru = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-04 08:00:00',
        ]);
        $tripBaru = TripModel::create([
            'id_jadwal'      => $jadwalBaru->id_jadwal,
            'status'         => 'selesai',
            'waktu_checkin'  => '2026-08-04 08:00:00',
            'waktu_checkout' => '2026-08-04 17:00:00',
        ]);

        $this->getJson('/api/trip/riwayat-saya?tanggal=2026-08-04')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_trip', $tripBaru->id_trip);

        $this->getJson('/api/trip/riwayat-saya?tanggal=2026-08-01')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_trip', $tripLama->id_trip);

        $this->getJson('/api/trip/riwayat-saya')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/trip/riwayat-saya?tanggal=04-08-2026')
            ->assertStatus(422);
    }

    public function test_riwayat_saya_menampilkan_armada_nopol_dari_alokasi_untuk_supir_shift(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        // Supir shift: penugasan TANPA id_armada — armada hariannya berasal
        // dari alokasi_armada (lihat AlokasiArmadaService::alokasikan()), bukan
        // dari kolom penugasan yang memang sengaja dibiarkan NULL.
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek, 'selesai');

        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $idArmada,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 9037 TMN',
            'merk'          => 'Hino',
            'dibuat_pada'   => now(),
        ]);
        DB::table('alokasi_armada')->insert([
            'id_alokasi'  => (string) Str::uuid(),
            'tanggal'     => '2026-08-06',
            'id_proyek'   => $proyek->id_proyek,
            'id_supir'    => $ctx->id_supir,
            'id_armada'   => $idArmada,
            'sumber'      => 'otomatis',
            'dibuat_pada' => now(),
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-06 07:00:00',
        ]);
        TripModel::create([
            'id_jadwal'      => $jadwal->id_jadwal,
            'status'         => 'selesai',
            'waktu_checkin'  => '2026-08-06 07:18:00',
            'waktu_checkout' => '2026-08-06 11:30:00',
        ]);

        $this->getJson('/api/trip/riwayat-saya')
            ->assertStatus(200)
            ->assertJsonPath('data.0.armada_nopol', 'B 9037 TMN');
    }

    public function test_riwayat_saya_tidak_tampilkan_trip_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $lain = $this->actingAsSupir();
        $penugasanLain = $this->makePenugasan($lain->id_supir, $proyek->id_proyek, 'selesai');
        $jadwalLain = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasanLain->id_penugasan,
            'waktu_berangkat' => now(),
        ]);
        TripModel::create([
            'id_jadwal'      => $jadwalLain->id_jadwal,
            'status'         => 'selesai',
            'waktu_checkin'  => now()->subHour(),
            'waktu_checkout' => now(),
        ]);

        $this->actingAsSupir();

        $this->getJson('/api/trip/riwayat-saya')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_detail_penugasan_menampilkan_trip_aktif_jika_ada(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);
        $trip = TripModel::create([
            'id_jadwal'     => $jadwal->id_jadwal,
            'status'        => 'berjalan',
            'waktu_checkin' => now(),
        ]);

        $response = $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}");

        $response->assertStatus(200)
            ->assertJsonPath('data.trip.id_trip', $trip->id_trip)
            ->assertJsonPath('data.trip.status', 'berjalan')
            ->assertJsonPath('data.trip.punya_laporan', false);

        $this->buatLaporanKosong($trip->id_trip);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}")
            ->assertStatus(200)
            ->assertJsonPath('data.trip.punya_laporan', true);
    }

    public function test_detail_penugasan_menampilkan_rute_tersedia_dari_proyek(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'CDD',
            'nama_jenis'         => 'CDD',
            'dibuat_pada'        => now(),
        ]);

        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $idRute,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RUTE-' . Str::random(6),
            'nama_rute'     => 'Jakarta - Bandung',
            'asal'          => 'Jakarta',
            'tujuan'        => 'Bandung',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        ProyekRuteModel::create([
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_proyek'          => $proyek->id_proyek,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenisKendaraan,
        ]);

        $response = $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.rute_tersedia')
            ->assertJsonPath('data.rute_tersedia.0.id_rute', $idRute)
            ->assertJsonPath('data.rute_tersedia.0.nama_rute', 'Jakarta - Bandung');
    }

    public function test_detail_penugasan_menyertakan_nama_rute_dan_uang_jalan_penugasan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();

        $idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'CDD',
            'nama_jenis'         => 'CDD',
            'dibuat_pada'        => now(),
        ]);

        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $idRute,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RUTE-' . Str::random(6),
            'nama_rute'     => 'Jakarta - Bandung',
            'asal'          => 'Jakarta',
            'tujuan'        => 'Bandung',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        ProyekRuteModel::create([
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_proyek'          => $proyek->id_proyek,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenisKendaraan,
        ]);

        $penugasan = PenugasanModel::create([
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_proyek'      => $proyek->id_proyek,
            'id_supir'       => $ctx->id_supir,
            'id_rute'        => $idRute,
            'status'         => 'aktif',
            'tanggal_tugas'  => now()->toDateString(),
            'estimasi_biaya' => 900000,
        ]);

        $response = $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}");

        $response->assertStatus(200)
            ->assertJsonPath('data.nama_rute', 'Jakarta - Bandung')
            ->assertJsonPath('data.uang_jalan', 900000);
    }

    public function test_riwayat_saya_termasuk_trip_dibatalkan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek, 'batal');

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);
        TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => 'dibatalkan',
        ]);

        $response = $this->getJson('/api/trip/riwayat-saya');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'dibatalkan');
    }

    public function test_riwayat_saya_tidak_tampilkan_trip_yang_masih_berjalan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);
        TripModel::create([
            'id_jadwal'     => $jadwal->id_jadwal,
            'status'        => 'berjalan',
            'waktu_checkin' => now(),
        ]);

        $response = $this->getJson('/api/trip/riwayat-saya');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_supir_tidak_bisa_mulai_trip_tanpa_absen(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(422);
    }

    public function test_supir_tidak_bisa_mulai_trip_jika_absen_berhalangan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $this->absenHadir($ctx->id_supir, 'berhalangan');

        $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(422);
    }

    public function test_supir_bisa_mulai_trip_untuk_penugasan_miliknya(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $this->absenHadir($ctx->id_supir);

        $response = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'berjalan');
        $this->assertNotNull($response->json('data.waktu_checkin'));
    }

    public function test_supir_tidak_bisa_mulai_trip_untuk_penugasan_milik_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $penugasan = $this->makePenugasan($pemilik->id_supir, $proyek->id_proyek);

        $this->actingAsSupir();

        $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(403);
    }

    public function test_supir_bisa_selesaikan_trip_tanpa_menutup_penugasan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $this->absenHadir($ctx->id_supir);

        $mulai = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTrip = $mulai->json('data.id_trip');
        $this->buatLaporanKosong($idTrip);

        $this->postJson("/api/trip/{$idTrip}/checkout-saya")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai');

        $this->assertSame('aktif', $penugasan->fresh()->status);
    }

    public function test_supir_bisa_mulai_trip_kedua_dari_penugasan_yang_sama(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $this->absenHadir($ctx->id_supir);

        $mulaiPertama = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTripPertama = $mulaiPertama->json('data.id_trip');
        $this->buatLaporanKosong($idTripPertama);

        $this->postJson("/api/trip/{$idTripPertama}/checkout-saya")->assertStatus(200);

        $mulaiKedua = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ]);

        $mulaiKedua->assertStatus(201)
            ->assertJsonPath('data.status', 'berjalan');
        $this->assertNotSame($idTripPertama, $mulaiKedua->json('data.id_trip'));
    }

    public function test_checkout_ditolak_tanpa_laporan_perjalanan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $this->absenHadir($ctx->id_supir);

        $mulai = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTrip = $mulai->json('data.id_trip');

        $this->postJson("/api/trip/{$idTrip}/checkout-saya")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Laporan perjalanan wajib diisi sebelum trip dapat diselesaikan');

        $this->buatLaporanKosong($idTrip);

        $this->postJson("/api/trip/{$idTrip}/checkout-saya")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai');
    }

    public function test_checkout_ulang_trip_yang_sudah_selesai_tetap_sukses(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $this->absenHadir($ctx->id_supir);

        $mulai = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTrip = $mulai->json('data.id_trip');
        $this->buatLaporanKosong($idTrip);

        $this->postJson("/api/trip/{$idTrip}/checkout-saya")->assertStatus(200);

        $this->postJson("/api/trip/{$idTrip}/checkout-saya")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai');
    }

    public function test_checkout_trip_dibatalkan_tetap_ditolak(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $this->absenHadir($ctx->id_supir);

        $mulai = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTrip = $mulai->json('data.id_trip');

        DB::table('trip')->where('id_trip', $idTrip)->update(['status' => 'dibatalkan']);

        $this->postJson("/api/trip/{$idTrip}/checkout-saya")
            ->assertStatus(422);
    }

    public function test_supir_tidak_bisa_selesaikan_trip_milik_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $penugasan = $this->makePenugasan($pemilik->id_supir, $proyek->id_proyek);
        $this->absenHadir($pemilik->id_supir);
        $mulai = $this->postJson('/api/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTrip = $mulai->json('data.id_trip');

        $this->actingAsSupir();

        $this->postJson("/api/trip/{$idTrip}/checkout-saya")
            ->assertStatus(403);
    }
}
