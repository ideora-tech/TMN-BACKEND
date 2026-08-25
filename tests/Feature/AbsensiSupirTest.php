<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AbsensiSupirTest extends TestCase
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

    public function test_hari_ini_saya_null_sebelum_absen(): void
    {
        $this->actingAsSupir();

        $this->getJson('/api/absensi-supir/hari-ini-saya')
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_supir_bisa_absen_hadir(): void
    {
        $this->actingAsSupir();

        $response = $this->postJson('/api/absensi-supir', [
            'status' => 'hadir',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'hadir');

        $this->getJson('/api/absensi-supir/hari-ini-saya')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'hadir');
    }

    public function test_supir_bisa_absen_berhalangan_dengan_keterangan(): void
    {
        $this->actingAsSupir();

        $response = $this->postJson('/api/absensi-supir', [
            'status'     => 'berhalangan',
            'keterangan' => 'Kendaraan mogok di jalan',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'berhalangan')
            ->assertJsonPath('data.keterangan', 'Kendaraan mogok di jalan');
    }

    public function test_absen_ulang_hari_yang_sama_menimpa_bukan_menduplikat(): void
    {
        $ctx = $this->actingAsSupir();

        $this->postJson('/api/absensi-supir', ['status' => 'berhalangan'])->assertStatus(201);
        $this->postJson('/api/absensi-supir', ['status' => 'hadir'])->assertStatus(201);

        $this->assertSame(1, DB::table('absensi_supir')
            ->where('id_supir', $ctx->id_supir)
            ->whereNull('dihapus_pada')
            ->count());

        $this->getJson('/api/absensi-supir/hari-ini-saya')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'hadir');
    }

    public function test_absen_hadir_dengan_foto_wajah_tersimpan(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $ctx = $this->actingAsSupir();

        $res = $this->post('/api/absensi-supir', [
            'status'      => 'hadir',
            'foto'        => \Illuminate\Http\UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'skor_wajah'  => 0.7654,
            'wajah_cocok' => 1,
        ], ['Accept' => 'application/json']);

        $res->assertStatus(201)->assertJsonPath('data.status', 'hadir');
        $this->assertNotNull($res->json('data.url_foto'));

        $baris = DB::table('absensi_supir')->where('id_supir', $ctx->id_supir)->first();
        $this->assertStringStartsWith('absensi-selfie/', $baris->foto);
        $this->assertEquals(0.7654, (float) $baris->skor_wajah);
        $this->assertSame(1, (int) $baris->wajah_cocok);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($baris->foto);
    }

    public function test_absen_hadir_tanpa_foto_tetap_diterima(): void
    {
        $ctx = $this->actingAsSupir();

        $this->postJson('/api/absensi-supir', ['status' => 'hadir'])->assertStatus(201);

        $baris = DB::table('absensi_supir')->where('id_supir', $ctx->id_supir)->first();
        $this->assertNull($baris->foto);
        $this->assertNull($baris->wajah_cocok);
    }

    public function test_absen_status_tidak_valid_ditolak(): void
    {
        $this->actingAsSupir();

        $this->postJson('/api/absensi-supir', ['status' => 'ngawur'])
            ->assertStatus(422);
    }

    public function test_absen_pengguna_tanpa_data_supir_404(): void
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

        $this->postJson('/api/absensi-supir', ['status' => 'hadir'])
            ->assertStatus(404);
    }
}
