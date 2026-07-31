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

        $response = $this->getJson("/api/v1/trip/penugasan-saya/{$penugasan->id_penugasan}");

        $response->assertStatus(200)
            ->assertJsonPath('data.penugasan.id_penugasan', $penugasan->id_penugasan)
            ->assertJsonPath('data.trip', null)
            ->assertJsonPath('data.rute_tersedia', []);
    }

    public function test_supir_tidak_bisa_lihat_penugasan_milik_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $penugasan = $this->makePenugasan($pemilik->id_supir, $proyek->id_proyek);

        $this->actingAsSupir();

        $this->getJson("/api/v1/trip/penugasan-saya/{$penugasan->id_penugasan}")
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

        $response = $this->getJson('/api/v1/trip/riwayat-saya');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'selesai');
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

        $this->getJson('/api/v1/trip/riwayat-saya')
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

        $response = $this->getJson("/api/v1/trip/penugasan-saya/{$penugasan->id_penugasan}");

        $response->assertStatus(200)
            ->assertJsonPath('data.trip.id_trip', $trip->id_trip)
            ->assertJsonPath('data.trip.status', 'berjalan');
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

        $response = $this->getJson("/api/v1/trip/penugasan-saya/{$penugasan->id_penugasan}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.rute_tersedia')
            ->assertJsonPath('data.rute_tersedia.0.id_rute', $idRute)
            ->assertJsonPath('data.rute_tersedia.0.nama_rute', 'Jakarta - Bandung');
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

        $response = $this->getJson('/api/v1/trip/riwayat-saya');

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

        $response = $this->getJson('/api/v1/trip/riwayat-saya');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_supir_bisa_mulai_trip_untuk_penugasan_miliknya(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $response = $this->postJson('/api/v1/trip/mulai-saya', [
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

        $this->postJson('/api/v1/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(403);
    }

    public function test_supir_bisa_selesaikan_trip_tanpa_menutup_penugasan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $mulai = $this->postJson('/api/v1/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTrip = $mulai->json('data.id_trip');

        $this->postJson("/api/v1/trip/{$idTrip}/checkout-saya")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai');

        $this->assertSame('aktif', $penugasan->fresh()->status);
    }

    public function test_supir_bisa_mulai_trip_kedua_dari_penugasan_yang_sama(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);

        $mulaiPertama = $this->postJson('/api/v1/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTripPertama = $mulaiPertama->json('data.id_trip');

        $this->postJson("/api/v1/trip/{$idTripPertama}/checkout-saya")->assertStatus(200);

        $mulaiKedua = $this->postJson('/api/v1/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ]);

        $mulaiKedua->assertStatus(201)
            ->assertJsonPath('data.status', 'berjalan');
        $this->assertNotSame($idTripPertama, $mulaiKedua->json('data.id_trip'));
    }

    public function test_supir_tidak_bisa_selesaikan_trip_milik_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $penugasan = $this->makePenugasan($pemilik->id_supir, $proyek->id_proyek);
        $mulai = $this->postJson('/api/v1/trip/mulai-saya', [
            'id_penugasan' => $penugasan->id_penugasan,
        ])->assertStatus(201);
        $idTrip = $mulai->json('data.id_trip');

        $this->actingAsSupir();

        $this->postJson("/api/v1/trip/{$idTrip}/checkout-saya")
            ->assertStatus(403);
    }
}
