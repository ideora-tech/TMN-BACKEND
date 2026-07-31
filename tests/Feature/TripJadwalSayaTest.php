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

    private function makeTrip(string $idPenugasan, string $status): string
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
            'id_trip'     => $idTrip,
            'id_jadwal'   => $idJadwal,
            'status'      => $status,
            'dibuat_pada' => now(),
        ]);

        return $idTrip;
    }

    public function test_jadwal_saya_tampilkan_penugasan_dalam_rentang_urut_tanggal(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $b = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-05');
        $a = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-03');
        $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-15');

        $res = $this->getJson('/api/v1/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-09');

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id_penugasan', $a->id_penugasan)
            ->assertJsonPath('data.0.tanggal_tugas', '2026-08-03')
            ->assertJsonPath('data.0.status', 'aktif')
            ->assertJsonPath('data.0.proyek.nama_proyek', 'Proyek Trip Jadwal Saya Test')
            ->assertJsonPath('data.0.trip_berjalan', null)
            ->assertJsonPath('data.1.id_penugasan', $b->id_penugasan);
    }

    public function test_jadwal_saya_tidak_tampilkan_penugasan_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $lain = $this->actingAsSupir();
        $this->makePenugasanTanggal($lain->id_supir, $proyek->id_proyek, '2026-08-03');

        $this->actingAsSupir();

        $this->getJson('/api/v1/trip/jadwal-saya?dari=2026-08-01&sampai=2026-08-07')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_jadwal_saya_menyertakan_trip_berjalan(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $p = $this->makePenugasanTanggal($ctx->id_supir, $proyek->id_proyek, '2026-08-03');
        $idTrip = $this->makeTrip($p->id_penugasan, 'berjalan');

        $this->getJson('/api/v1/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-03')
            ->assertStatus(200)
            ->assertJsonPath('data.0.trip_berjalan.id_trip', $idTrip)
            ->assertJsonPath('data.0.trip_berjalan.status', 'berjalan');
    }

    public function test_jadwal_saya_validasi_parameter(): void
    {
        $this->actingAsSupir();

        $this->getJson('/api/v1/trip/jadwal-saya')->assertStatus(422);
        $this->getJson('/api/v1/trip/jadwal-saya?dari=03-08-2026&sampai=2026-08-09')->assertStatus(422);
        $this->getJson('/api/v1/trip/jadwal-saya?dari=2026-08-09&sampai=2026-08-03')->assertStatus(422);
        $this->getJson('/api/v1/trip/jadwal-saya?dari=2026-01-01&sampai=2026-12-31')->assertStatus(422);
    }

    public function test_jadwal_saya_pengguna_tanpa_data_supir_404(): void
    {
        $this->actingAsPenggunaTanpaSupir();

        $this->getJson('/api/v1/trip/jadwal-saya?dari=2026-08-03&sampai=2026-08-09')
            ->assertStatus(404);
    }
}
