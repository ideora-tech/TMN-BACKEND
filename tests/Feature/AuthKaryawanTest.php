<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthKaryawanTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_menyertakan_nama_karyawan_saat_pengguna_terhubung_ke_karyawan(): void
    {
        $this->ensurePerusahaan();

        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $idKaryawan,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => 'Rina Kartika',
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);

        Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'kode_peran'    => 'ADMIN',
            'username'      => 'rina_test',
            'email'         => 'rina@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'rina_test',
            'password' => 'Password123!',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.pengguna.karyawan.nama_karyawan', 'Rina Kartika')
            ->assertJsonPath('data.pengguna.karyawan.id_karyawan', $idKaryawan);
    }

    public function test_login_pengguna_tanpa_karyawan_tidak_error(): void
    {
        $this->ensurePerusahaan();

        Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'ADMIN',
            'username'      => 'admin_test',
            'email'         => 'admin@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin_test',
            'password' => 'Password123!',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.pengguna.karyawan', null);
    }

    public function test_login_gagal_dengan_password_salah(): void
    {
        $this->ensurePerusahaan();

        Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'ADMIN',
            'username'      => 'salah_test',
            'email'         => 'salah@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'salah_test',
            'password' => 'PasswordSalah!',
        ]);

        $res->assertStatus(401);
    }
}
