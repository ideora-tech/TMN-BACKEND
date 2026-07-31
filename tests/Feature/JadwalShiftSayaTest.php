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

class JadwalShiftSayaTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_PENUGASAN = 'aaaa4444-0000-4000-8000-000000000001';

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
            'id_menu'     => self::ID_MENU_PENUGASAN,
            'nama_menu'   => 'Penugasan',
            'path'        => '/penugasan',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);
        DB::table('izin_peran')->insertOrIgnore([
            'id_izin'     => (string) Str::uuid(),
            'kode_peran'  => 'SUPIR',
            'id_menu'     => self::ID_MENU_PENUGASAN,
            'aksi'        => 'lihat',
            'diizinkan'   => 1,
            'dibuat_pada' => now(),
        ]);

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
            'nama_klien'    => 'Klien Jadwal Shift Saya Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Jadwal Shift Saya Test',
        ]);
    }

    private function makePenugasan(string $idSupir, string $idProyek): PenugasanModel
    {
        return PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $idProyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
    }

    private function makeShift(): string
    {
        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'      => $idShift,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Pagi',
            'jam_mulai'     => '06:00:00',
            'jam_selesai'   => '14:00:00',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $idShift;
    }

    public function test_supir_bisa_lihat_jadwal_shift_hari_ini_kalau_ada(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $this->makePenugasan($ctx->id_supir, $proyek->id_proyek);
        $idShift = $this->makeShift();

        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(),
            'id_proyek'       => $proyek->id_proyek,
            'id_shift'        => $idShift,
            'id_supir'        => $ctx->id_supir,
            'tanggal'         => now()->toDateString(),
            'dibuat_pada'     => now(),
        ]);

        $response = $this->getJson('/api/v1/jadwal-shift/hari-ini-saya');

        if ($response->status() === 500) {
            fwrite(STDERR, print_r(DB::table('log_error')->latest('dibuat_pada')->first(), true));
        }

        $response->assertStatus(200)
            ->assertJsonPath('data.shift_nama', 'Pagi')
            ->assertJsonPath('data.jam_mulai', '06:00:00')
            ->assertJsonPath('data.nama_proyek', $proyek->nama_proyek);
    }

    public function test_supir_dapat_null_kalau_tidak_ada_jadwal_shift_hari_ini(): void
    {
        $this->actingAsSupir();

        $response = $this->getJson('/api/v1/jadwal-shift/hari-ini-saya');

        $response->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_supir_tidak_lihat_jadwal_shift_supir_lain(): void
    {
        $proyek = $this->makeProyek();
        $pemilik = $this->actingAsSupir();
        $this->makePenugasan($pemilik->id_supir, $proyek->id_proyek);
        $idShift = $this->makeShift();

        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(),
            'id_proyek'       => $proyek->id_proyek,
            'id_shift'        => $idShift,
            'id_supir'        => $pemilik->id_supir,
            'tanggal'         => now()->toDateString(),
            'dibuat_pada'     => now(),
        ]);

        $this->actingAsSupir();

        $this->getJson('/api/v1/jadwal-shift/hari-ini-saya')
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }
}
