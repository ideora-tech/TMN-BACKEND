<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaporanSayaTest extends TestCase
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
        foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
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
            'nama_klien'    => 'Klien Laporan Saya Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Laporan Saya Test',
        ]);
    }

    private function makeTripUntukSupir(string $idSupir, string $idProyek, string $status): TripModel
    {
        $penugasan = PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $idProyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);

        return TripModel::create([
            'id_jadwal'      => $jadwal->id_jadwal,
            'status'         => $status,
            'waktu_checkin'  => now()->subHours(2),
            'waktu_checkout' => $status === 'selesai' ? now() : null,
        ]);
    }

    public function test_laporan_saya_null_sebelum_dibuat(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $trip = $this->makeTripUntukSupir($ctx->id_supir, $proyek->id_proyek, 'selesai');

        $this->getJson("/api/trip/{$trip->id_trip}/laporan-saya")
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_supir_bisa_membuat_laporan_untuk_trip_selesai_miliknya(): void
    {
        Storage::fake('public');
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $trip = $this->makeTripUntukSupir($ctx->id_supir, $proyek->id_proyek, 'selesai');

        $res = $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'biaya_bbm'       => 300000,
            'jarak_tempuh_km' => 85,
            'uang_jalan'      => 150000,
            'uang_tol'        => 20000,
            'catatan_insiden' => 'Lancar tanpa kendala',
            'foto'            => [UploadedFile::fake()->image('bukti.jpg')],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_trip', $trip->id_trip)
            ->assertJsonPath('data.biaya_bbm', 300000)
            ->assertJsonCount(1, 'data.foto');

        $this->assertDatabaseHas('laporan_perjalanan', [
            'id_trip'       => $trip->id_trip,
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_supir_tidak_bisa_membuat_laporan_untuk_trip_milik_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $trip = $this->makeTripUntukSupir($pemilik->id_supir, $proyek->id_proyek, 'selesai');

        $this->actingAsSupir();

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'biaya_bbm'       => 300000,
            'jarak_tempuh_km' => 85,
            'uang_jalan'      => 150000,
        ])->assertStatus(403);
    }

    public function test_supir_tidak_bisa_membuat_laporan_untuk_trip_belum_mulai(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $trip = $this->makeTripUntukSupir($ctx->id_supir, $proyek->id_proyek, 'belum_mulai');

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'biaya_bbm'       => 300000,
            'jarak_tempuh_km' => 85,
            'uang_jalan'      => 150000,
        ])->assertStatus(422);
    }

    public function test_supir_bisa_membuat_laporan_untuk_trip_berjalan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $trip = $this->makeTripUntukSupir($ctx->id_supir, $proyek->id_proyek, 'berjalan');

        Storage::fake('public');
        $res = $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'biaya_bbm'       => 300000,
            'jarak_tempuh_km' => 85,
            'uang_jalan'      => 150000,
            'foto'            => [UploadedFile::fake()->image('bukti.jpg')],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_trip', $trip->id_trip);
    }

    public function test_kirim_laporan_saya_dua_kali_menimpa_bukan_menduplikat(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $trip = $this->makeTripUntukSupir($ctx->id_supir, $proyek->id_proyek, 'selesai');

        Storage::fake('public');
        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'biaya_bbm'       => 300000,
            'jarak_tempuh_km' => 85,
            'uang_jalan'      => 150000,
            'foto'            => [UploadedFile::fake()->image('bukti.jpg')],
        ])->assertStatus(201);

        $res = $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'biaya_bbm'       => 350000,
            'jarak_tempuh_km' => 90,
            'uang_jalan'      => 150000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.biaya_bbm', 350000);

        $this->assertSame(1, DB::table('laporan_perjalanan')
            ->where('id_trip', $trip->id_trip)
            ->whereNull('dihapus_pada')
            ->count());
    }

    public function test_supir_bisa_hapus_foto_laporan_miliknya(): void
    {
        Storage::fake('public');
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $trip = $this->makeTripUntukSupir($ctx->id_supir, $proyek->id_proyek, 'selesai');

        $createRes = $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'biaya_bbm'       => 300000,
            'jarak_tempuh_km' => 85,
            'uang_jalan'      => 150000,
            'foto'            => [UploadedFile::fake()->image('bukti.jpg')],
        ]);
        $idLaporan = $createRes->json('data.id_laporan');
        $idFoto = $createRes->json('data.foto.0.id_foto');

        $this->deleteJson("/api/laporan-saya/{$idLaporan}/foto/{$idFoto}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('foto_laporan_perjalanan', [
            'id_foto'      => $idFoto,
            'dihapus_pada' => null,
        ]);
    }

    public function test_supir_tidak_bisa_upload_foto_laporan_milik_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $trip = $this->makeTripUntukSupir($pemilik->id_supir, $proyek->id_proyek, 'selesai');
        Storage::fake('public');
        $createRes = $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'biaya_bbm'       => 300000,
            'jarak_tempuh_km' => 85,
            'uang_jalan'      => 150000,
            'foto'            => [UploadedFile::fake()->image('bukti.jpg')],
        ]);
        $idLaporan = $createRes->json('data.id_laporan');

        $this->actingAsSupir();

        $this->postJson("/api/laporan-saya/{$idLaporan}/foto", [
            'foto' => [UploadedFile::fake()->image('bukti.jpg')],
        ])->assertStatus(403);
    }

    public function test_laporan_saya_pengguna_tanpa_data_supir_404(): void
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

        Sanctum::actingAs($pengguna, ['*']);

        $this->getJson('/api/trip/' . (string) Str::uuid() . '/laporan-saya')
            ->assertStatus(404);
    }
}
